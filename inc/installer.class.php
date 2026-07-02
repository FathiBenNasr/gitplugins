<?php
/**
 * Git Plugin Installer — install/update orchestration (reuse the native seam).
 *
 * decideAction() is PURE (unit-tested): given installed vs available versions it
 * returns install | update | skip | blocked_downgrade. The runner wires the
 * owned half (SSRF fetch → sanitised extract → atomic place) to GLPI core's
 * PUBLIC install/activate seam (Plugin::install()/activate()) — exactly how the
 * marketplace installs, so the target plugin's own install hooks run. Network +
 * install work is driven from cron, never inline in a web request (#7/#11).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsInstaller
{
    /**
     * Pure decision: what should happen for a managed plugin?
     *  - 'install'           : not currently installed
     *  - 'update'            : available is newer
     *  - 'blocked_downgrade' : available is OLDER and not forced (A04 refusal)
     *  - 'skip'              : already at/above available
     *
     * @param string $installed installed version ('' = not installed)
     * @param string $available available version ('' = unknown)
     */
    public static function decideAction(string $installed, string $available, bool $force = false): string
    {
        $installed = trim($installed);
        $available = trim($available);

        if ($installed === '') {
            return 'install';
        }
        if ($available === '') {
            return 'skip';
        }
        if (PluginGitpluginsVersion::isNewer($available, $installed)) {
            return 'update';
        }
        // Same version → nothing to do.
        if (PluginGitpluginsVersion::normalise($available) === PluginGitpluginsVersion::normalise($installed)) {
            return 'skip';
        }
        // available < installed → downgrade.
        return $force ? 'update' : 'blocked_downgrade';
    }

    /**
     * Resolve the archive URL for a source row at its resolved ref. Returns
     * [url, ref] or null. Pure (delegates to RefResolver).
     *
     * @return array{0:string,1:string}|null
     */
    public static function archiveFor(array $source, string $resolvedRef): ?array
    {
        $url = PluginGitpluginsRefResolver::archiveUrl(
            (string) ($source['provider'] ?? 'unknown'),
            (string) ($source['url'] ?? ''),
            $resolvedRef
        );

        return $url !== null ? [$url, $resolvedRef] : null;
    }

    /**
     * Run a pending install/update for one source: fetch → extract → place →
     * native install/activate. Live-box only (network + FS + core seam). Records
     * the audit trail and updates the installs row. Returns true on success.
     */
    public static function run(array $source, string $resolvedRef, string $resolvedSha = ''): bool
    {
        /** @var DBmysql $DB */
        global $DB;
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $sourceId = (int) ($source['id'] ?? 0);
        $key      = (string) ($source['plugin_key'] ?? '');
        $cfg      = PluginGitpluginsConfig::singleton();

        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            self::fail($sourceId, $key, 'invalid_plugin_key');

            return false;
        }

        $token  = PluginGitpluginsSource::decryptCredential($source['credential'] ?? null);
        $policy = (string) ($source['ref_policy'] ?? 'latest_tag');

        // Resolve the archive URL. For the `release` policy we resolve a pre-built
        // release asset (.tgz) via the releases API (network, SSRF-guarded); for
        // every other policy we build the git source-tarball URL (pure).
        if ($policy === 'release') {
            try {
                [$archiveUrl] = PluginGitpluginsFetcher::resolveReleaseAsset(
                    (string) ($source['provider'] ?? 'unknown'),
                    (string) ($source['url'] ?? ''),
                    $resolvedRef,
                    $cfg->getAllowedHosts(),
                    $token,
                    $cfg->getFetchTimeoutSeconds()
                );
            } catch (\Throwable $e) {
                self::fail($sourceId, $key, $e->getMessage());

                return false;
            }
        } else {
            $built = self::archiveFor($source, $resolvedRef);
            if ($built === null) {
                self::fail($sourceId, $key, 'invalid_ref');

                return false;
            }
            [$archiveUrl] = $built;
        }

        $archive = null;
        $staged  = null;
        try {
            $archive = PluginGitpluginsFetcher::fetch(
                $archiveUrl,
                $cfg->getAllowedHosts(),
                $token,
                $cfg->getMaxDownloadBytes(),
                $cfg->getFetchTimeoutSeconds()
            );

            // Capture the outgoing version to label the neutralised backup.
            $priorVersion = self::installedVersion($key);

            $staged      = PluginGitpluginsExtractor::extractTo($archive, $key);
            $pluginsBase = self::pluginsDir();

            // R6 preflight gate: refuse to place a plugin this box can't run
            // (GLPI/PHP version out of range, or a required PHP extension
            // missing) — otherwise it half-installs, deactivates and 404s. Runs
            // on the STAGED tree, before anything touches plugins/.
            $preflight = self::preflight($staged, $key);
            if (!$preflight['ok']) {
                PluginGitpluginsLog::record($sourceId, 'install', 'error', 'preflight_blocked: ' . implode('; ', $preflight['blockers']), $resolvedRef, $resolvedSha);
                throw new \RuntimeException('preflight_blocked');
            }

            // R5 build (opt-in per source): a source tarball ships no vendor/ —
            // build it in the staged dir before it goes live. Third-party build
            // code, so OFF unless the source explicitly enabled it.
            $buildOptIn = (bool) ($source['build_on_install'] ?? false);
            if ($buildOptIn) {
                PluginGitpluginsBuilder::run($staged, true, $cfg->getBuildTimeoutSeconds());
            }

            // R7 locale compile: compile any shipped-only .po so translations
            // don't silently fall back to English. Best-effort.
            $compiled = PluginGitpluginsLocales::compile($staged);
            if ($compiled > 0) {
                PluginGitpluginsLog::record($sourceId, 'install', 'ok', "compiled {$compiled} locale(s)", $resolvedRef, $resolvedSha);
            }

            // placeAtomically returns the path of an inert, out-of-web backup zip
            // of the prior install (null on a fresh install / if zipping failed).
            $backupZip = PluginGitpluginsExtractor::placeAtomically(
                $staged,
                $pluginsBase,
                $key,
                $priorVersion,
                $cfg->getCarryOverDirs()
            );
            $staged = null; // moved into place; nothing left to clean

            // R8 DB snapshot: R2 restores FILES; a failed migration can still
            // leave the schema ahead of the restored code. Gzip-dump the
            // plugin's OWN tables (scoped, bounded) so a rollback restores schema
            // too. Best-effort — a skipped/over-cap dump never blocks the update.
            $dbSnapshot = ($priorVersion !== '')
                ? PluginGitpluginsSnapshot::dumpOwnedTables($key, $cfg->getSnapshotMaxMb())
                : null;

            // Drive GLPI core's public install/activate seam (marketplace-equiv).
            self::nativeInstall($key);

            // Post-install verify + self-heal (R3): a code/DB version mismatch
            // silently deactivates the plugin and 404s every page. If it is not
            // active+versioned after one idempotent repair, roll back to the
            // neutralised backup and fail — never leave a broken/half tree live.
            if (!self::verifyInstalled($key)) {
                if (is_string($backupZip)
                    && PluginGitpluginsBackup::restore($backupZip, $pluginsBase, $key)) {
                    // Restore the schema too (R8) BEFORE re-registering, so the
                    // restored code meets the version of the DB it expects.
                    if (is_string($dbSnapshot)) {
                        PluginGitpluginsSnapshot::restore($dbSnapshot);
                    }
                    self::nativeInstall($key); // re-register the restored version
                    @unlink($backupZip);
                }
                if (is_string($dbSnapshot)) {
                    @unlink($dbSnapshot);
                }
                throw new \RuntimeException('verify_failed');
            }

            // Verified healthy → the backup has done its job. Retention of prior
            // versions for user-initiated rollback is a later phase (P2); for now
            // drop the archive + snapshot so they do not accumulate.
            if (is_string($backupZip)) {
                @unlink($backupZip);
            }
            if (is_string($dbSnapshot)) {
                @unlink($dbSnapshot);
            }

            // FIX 1: a successful install/update clears pending_action back to
            // 'none', stores the new installed sha/version, and clears the
            // update_available flag — so the next check is stable and the plugin
            // isn't re-flagged or re-queued on the following tick. Upsert by
            // plugin_key (UNIQUE) — never a blind insert, so no duplicate rows.
            $installedVersion = self::installedVersion($key);
            $DB->updateOrInsert('glpi_plugin_gitplugins_installs', [
                'plugin_key'                   => $key,
                'plugin_gitplugins_sources_id' => $sourceId,
                'installed_version'            => $installedVersion,
                'installed_sha'                => $resolvedSha !== '' ? mb_substr($resolvedSha, 0, 64) : null,
                'available_version'            => $installedVersion !== '' ? mb_substr($installedVersion, 0, 64) : null,
                'available_sha'                => $resolvedSha !== '' ? mb_substr($resolvedSha, 0, 64) : null,
                'update_available'             => 0,
                'pending_action'               => 'none',
                'last_result'                  => 'ok',
                'last_error'                   => null,
                'last_install_at'              => date('Y-m-d H:i:s'),
            ], ['plugin_key' => $key]);

            PluginGitpluginsLog::record($sourceId, 'install', 'ok', 'installed ' . $key, $resolvedRef, $resolvedSha);

            return true;
        } catch (\Throwable $e) {
            // Generic message only (no upstream/stack detail to the user/log).
            self::fail($sourceId, $key, $e->getMessage());

            return false;
        } finally {
            if (is_string($archive)) {
                @unlink($archive);
            }
            if (is_string($staged) && $staged !== '') {
                PluginGitpluginsExtractor::rrmdir(dirname($staged));
            }
        }
    }

    /** Drive the native, source-agnostic install + activate machinery. */
    private static function nativeInstall(string $key): void
    {
        $plugin = new Plugin();
        // checkPluginState picks up the freshly-placed files into glpi_plugins.
        $plugin->checkPluginState($key);
        if (!$plugin->getFromDBbyDir($key)) {
            throw new \RuntimeException('install_failed');
        }
        $id = (int) $plugin->fields['id'];
        // install() runs the target plugin's own install hook (like marketplace).
        $plugin->install($id);
        $plugin->activate($id);
        // R4: stale plugin route/asset cache is our most common post-update 404
        // cause (the README's manual cache-clear step). Best effort — a backend
        // without a working clear() must never fail the install.
        self::clearCaches();
    }

    /**
     * R6 gate: read the staged plugin.xml (best-effort), merge declared + known
     * requirements, and check them against this box. Returns the Preflight
     * verdict; a missing/unparseable manifest yields ok=true with only the
     * built-in extension heuristic applied (never blocks on absence of data).
     *
     * @return array{ok:bool,blockers:string[],warnings:string[]}
     */
    private static function preflight(string $staged, string $key): array
    {
        $info = [];
        $xmlPath = $staged . '/plugin.xml';
        if (is_file($xmlPath)) {
            $xml = @file_get_contents($xmlPath);
            if (is_string($xml)) {
                $parsed = PluginGitpluginsManifest::parseInfo($xml);
                if (is_array($parsed)) {
                    $info = $parsed;
                }
            }
        }
        $req = PluginGitpluginsPreflight::requirementsFor($info, $key);

        return PluginGitpluginsPreflight::checkEnvironment($req);
    }

    /** Best-effort GLPI cache invalidation after activate (gated by config). */
    private static function clearCaches(): void
    {
        if (!PluginGitpluginsConfig::singleton()->autoCacheClear()) {
            return;
        }
        try {
            /** @var mixed $GLPI_CACHE */
            global $GLPI_CACHE;
            if (is_object($GLPI_CACHE) && method_exists($GLPI_CACHE, 'clear')) {
                $GLPI_CACHE->clear();
            }
        } catch (\Throwable $e) {
            // ignore — cache clear is best effort.
        }
    }

    /**
     * PURE: is a post-install plugin state healthy — ACTIVE and carrying a
     * version? Extracted for unit testing (the FS/DB read lives in
     * verifyInstalled). $activated is Plugin::ACTIVATED, passed in so the test
     * needs no GLPI bootstrap.
     */
    public static function isHealthyState(int $state, string $version, int $activated): bool
    {
        return $state === $activated && trim($version) !== '';
    }

    /**
     * Confirm the plugin is registered, ACTIVE and versioned after install,
     * retrying one idempotent install()+activate() before giving up (a version
     * bump can leave it flagged TOBECONFIGURED/TOBEUPDATED). Returns false if
     * still unhealthy — the caller then rolls back to the neutralised backup.
     */
    public static function verifyInstalled(string $key): bool
    {
        $activated = defined('Plugin::ACTIVATED') ? Plugin::ACTIVATED : 1;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $plugin = new Plugin();
            if (!$plugin->getFromDBbyDir($key)) {
                return false;
            }
            $state   = (int) ($plugin->fields['state'] ?? 0);
            $version = (string) ($plugin->fields['version'] ?? '');
            if (self::isHealthyState($state, $version, (int) $activated)) {
                return true;
            }
            // One idempotent repair attempt before rolling back.
            $id = (int) $plugin->fields['id'];
            try {
                $plugin->install($id);
                $plugin->activate($id);
            } catch (\Throwable $e) {
                return false;
            }
        }

        return false;
    }

    private static function fail(int $sourceId, string $key, string $reason): void
    {
        /** @var DBmysql $DB */
        global $DB;

        // Upsert by plugin_key (UNIQUE) so a failed attempt doesn't spawn a
        // duplicate row. Clears pending so the cron doesn't retry endlessly.
        $DB->updateOrInsert('glpi_plugin_gitplugins_installs', [
            'plugin_key'                   => $key,
            'plugin_gitplugins_sources_id' => $sourceId,
            'pending_action'               => 'none',
            'last_result'                  => 'error',
            'last_error'                   => mb_substr($reason, 0, 255),
        ], ['plugin_key' => $key]);
        PluginGitpluginsLog::record($sourceId, 'install', 'error', $reason);
    }

    /** Read the installed version of a plugin from glpi_plugins (READ-only). */
    public static function installedVersion(string $key): string
    {
        $plugin = new Plugin();
        if ($plugin->getFromDBbyDir($key)) {
            return (string) ($plugin->fields['version'] ?? '');
        }

        return '';
    }

    /** Absolute plugins/ directory (where extracted plugins are placed). */
    public static function pluginsDir(): string
    {
        if (defined('GLPI_ROOT')) {
            return GLPI_ROOT . '/plugins';
        }

        return dirname(__DIR__, 2); // .../plugins
    }
}
