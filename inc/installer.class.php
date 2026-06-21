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

        $built = self::archiveFor($source, $resolvedRef);
        if ($built === null) {
            self::fail($sourceId, $key, 'invalid_ref');

            return false;
        }
        [$archiveUrl] = $built;

        $token   = PluginGitpluginsSource::decryptCredential($source['credential'] ?? null);
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

            $staged      = PluginGitpluginsExtractor::extractTo($archive, $key);
            $pluginsBase = self::pluginsDir();
            PluginGitpluginsExtractor::placeAtomically($staged, $pluginsBase, $key);
            $staged = null; // moved into place; nothing left to clean

            // Drive GLPI core's public install/activate seam (marketplace-equiv).
            self::nativeInstall($key);

            $installedVersion = self::installedVersion($key);
            $DB->updateOrInsert('glpi_plugin_gitplugins_installs', [
                'plugin_key'        => $key,
                'installed_version' => $installedVersion,
                'installed_sha'     => $resolvedSha !== '' ? mb_substr($resolvedSha, 0, 64) : null,
                'pending_action'    => 'none',
                'last_result'       => 'ok',
                'last_error'        => null,
                'last_install_at'   => date('Y-m-d H:i:s'),
            ], ['plugin_gitplugins_sources_id' => $sourceId]);

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
    }

    private static function fail(int $sourceId, string $key, string $reason): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->updateOrInsert('glpi_plugin_gitplugins_installs', [
            'plugin_key'     => $key,
            'pending_action' => 'none',
            'last_result'    => 'error',
            'last_error'     => mb_substr($reason, 0, 255),
        ], ['plugin_gitplugins_sources_id' => $sourceId]);
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
