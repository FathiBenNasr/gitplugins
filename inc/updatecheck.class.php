<?php
/**
 * Git Plugin Installer — cron update-checker + deferred install runner.
 *
 * Two jobs, both OUT of the web request (#7/#11):
 *  1. For each active source, resolve the latest ref/SHA (SSRF-guarded tags API)
 *     and compare against the installed version; write available_* + last_check.
 *  2. Execute any rows flagged pending_action by front/install.php (a crash
 *     leaves them pending + a backup dir, so the next tick resumes — resumable).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

class PluginGitpluginsUpdatecheck extends CommonGLPI
{
    /** Localised CronTask label for the Automatic Actions UI. */
    public static function getTypeName($nb = 0): string
    {
        return __('Git plugin update check', 'gitplugins');
    }

    /** Friendly label in the Automatic Actions UI (per cron method). */
    public static function cronInfo(string $name): array
    {
        return match ($name) {
            'notifyUpdates' => ['description' => __('Email a digest of managed plugins that have an available update', 'gitplugins')],
            default         => ['description' => __('Check managed git sources for updates and run pending installs', 'gitplugins')],
        };
    }

    /**
     * Cron entry: resolve latest refs, record availability, then run pending
     * installs. Rate-limited by GLPI's cron scheduling itself. Returns >0 when
     * work was done (CronTask success), 0 when idle.
     */
    public static function cronCheckUpdates(CronTask $task): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $did = 0;
        $cfg = PluginGitpluginsConfig::singleton();

        foreach (PluginGitpluginsSource::activeRows(false) as $sourceId => $src) {
            $key = (string) ($src['plugin_key'] ?? '');
            if ($key === '') {
                continue;
            }

            // ----- availability check (resolve the remote ref/sha/version) -----
            $resolved  = self::resolveLatest($src, $cfg);
            $installed = PluginGitpluginsInstaller::installedVersion($key);

            // Current install-state row (keyed by plugin_key — one row per plugin).
            $row = self::installRow($key);

            $installedSha = (string) ($row['installed_sha'] ?? '');
            $available    = (string) ($resolved['version'] ?? '');
            $availSha     = (string) ($resolved['sha'] ?? '');

            // FIX 1: an update is "available" only when the resolved remote differs
            // from what is installed — by SHA when we have one (track_branch /
            // pin_sha), else by version/tag. Same SHA/version → no update flag,
            // so a branch policy stops re-queuing on every tick.
            $updateAvail = self::isUpdateAvailable(
                $installed,
                $installedSha,
                $available,
                $availSha,
                (string) ($src['ref_policy'] ?? 'latest_tag')
            );

            // Upsert by plugin_key (UNIQUE) — never a blind insert, so no duplicate
            // rows accumulate. Preserve pending_action / install bookkeeping; only
            // refresh the availability fields here.
            $DB->updateOrInsert('glpi_plugin_gitplugins_installs', [
                'plugin_key'                   => $key,
                'plugin_gitplugins_sources_id' => (int) $sourceId,
                'installed_version'            => $installed,
                'available_version'            => $available !== '' ? mb_substr($available, 0, 64) : null,
                'available_sha'                => $availSha !== '' ? mb_substr($availSha, 0, 64) : null,
                'update_available'             => $updateAvail ? 1 : 0,
                'last_check_at'                => date('Y-m-d H:i:s'),
            ], ['plugin_key' => $key]);
            $task->addVolume(1);
            $did++;

            // ----- run a pending install/update queued by the admin -----
            // Re-read the row (the upsert may have just created it) and only run a
            // pending action when there is genuinely something to apply.
            $row = self::installRow($key);
            $pending = (string) ($row['pending_action'] ?? 'none');
            if (in_array($pending, ['install', 'update'], true)) {
                // For an 'update', skip the run when nothing actually differs —
                // prevents the reinstall-every-tick loop. A fresh 'install'
                // (nothing on disk) always proceeds.
                $proceed = $pending === 'install' || $installed === '' || $updateAvail;
                $ref     = (string) ($resolved['ref'] ?? ($src['ref'] ?? ''));
                if ($proceed && ($ref !== '' || (string) ($src['ref_policy'] ?? '') === 'release')) {
                    // run() clears pending_action back to 'none' and records the new
                    // installed sha/version, so the next check is stable.
                    PluginGitpluginsInstaller::run($src, $ref, $availSha);
                    $did++;
                } elseif (!$proceed) {
                    // Nothing to do → clear the stale pending flag so we don't retry.
                    $DB->update('glpi_plugin_gitplugins_installs',
                        ['pending_action' => 'none'],
                        ['plugin_key' => $key]
                    );
                }
            }
        }

        return $did > 0 ? 1 : 0;
    }

    /** Fetch the install-state row for a plugin key (keyed lookup). [] if none. */
    private static function installRow(string $key): array
    {
        /** @var DBmysql $DB */
        global $DB;

        return $DB->request([
            'FROM'  => 'glpi_plugin_gitplugins_installs',
            'WHERE' => ['plugin_key' => $key],
            'LIMIT' => 1,
        ])->current() ?: [];
    }

    /**
     * PURE update decision: is the resolved remote DIFFERENT from what is on disk?
     *
     * Prefers SHA equality when both sides carry one (the authoritative signal for
     * track_branch / pin_sha — a branch that hasn't moved has the same HEAD sha so
     * we must NOT flag an update). Otherwise compares normalised versions/tags
     * (latest_tag / release). Unknown remote (empty available + empty sha) → no
     * update. Nothing installed yet → an update IS available iff the remote is
     * known.
     */
    public static function isUpdateAvailable(
        string $installedVersion,
        string $installedSha,
        string $availableVersion,
        string $availableSha,
        string $policy
    ): bool {
        $installedSha = strtolower(trim($installedSha));
        $availableSha = strtolower(trim($availableSha));

        // SHA-driven policies: compare SHAs when we have both.
        if ($availableSha !== '' && $installedSha !== '') {
            return $availableSha !== $installedSha;
        }

        $av = PluginGitpluginsVersion::normalise($availableVersion);
        $iv = PluginGitpluginsVersion::normalise($installedVersion);

        // Remote completely unknown → can't claim an update.
        if ($av === '' && $availableSha === '') {
            return false;
        }
        // Nothing installed but a known remote → update (i.e. install) available.
        if ($iv === '' && $installedSha === '') {
            return true;
        }
        // A known remote SHA we have never installed (no installed sha) → update.
        if ($av === '' && $availableSha !== '') {
            return $installedSha === '' || $availableSha !== $installedSha;
        }

        // Version/tag comparison.
        return $av !== $iv;
    }

    // ===================== FEATURE 3: e-mail update digest =====================

    /**
     * Cron entry: e-mail a digest of managed plugins that have an available update
     * (marketplace parity). Sends only when notifications are enabled AND at least
     * one row's available set has CHANGED since it was last notified (anti-spam).
     * Network-free except the mailer; reads stored update_available (no fetch).
     */
    public static function cronNotifyUpdates(CronTask $task): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $cfg = PluginGitpluginsConfig::singleton();
        if (!$cfg->notifyUpdates()) {
            return 0;
        }

        // Pull every row currently flagged with an available update, carrying the
        // source repo URL for the digest body (LEFT JOIN — a row may outlive its
        // source). READ-only over our own tables.
        $rows = [];
        foreach ($DB->request([
            'SELECT' => [
                'glpi_plugin_gitplugins_installs.*',
                'glpi_plugin_gitplugins_sources.url AS repo',
            ],
            'FROM'      => 'glpi_plugin_gitplugins_installs',
            'LEFT JOIN' => [
                'glpi_plugin_gitplugins_sources' => [
                    'ON' => [
                        'glpi_plugin_gitplugins_installs' => 'plugin_gitplugins_sources_id',
                        'glpi_plugin_gitplugins_sources'  => 'id',
                    ],
                ],
            ],
            'WHERE' => ['glpi_plugin_gitplugins_installs.update_available' => 1],
        ]) as $r) {
            $rows[] = $r;
        }

        // Anti-spam: only the rows whose available set differs from what we last
        // e-mailed. If none changed, stay silent even though updates exist.
        $toNotify = array_values(array_filter($rows, static fn (array $r): bool => self::shouldNotifyRow($r)));
        if ($toNotify === []) {
            return 0;
        }

        $recipients = self::digestRecipients($cfg);
        if ($recipients === []) {
            return 0;
        }

        [$subject, $body] = self::buildDigest($toNotify);
        if (!self::sendDigest($recipients, $subject, $body)) {
            return 0;
        }

        // Stamp each notified row so the same available set isn't e-mailed again.
        $now = date('Y-m-d H:i:s');
        foreach ($toNotify as $r) {
            $DB->update('glpi_plugin_gitplugins_installs', [
                'last_notified_sha' => mb_substr(self::availSignature($r), 0, 64),
                'last_notified_at'  => $now,
            ], ['id' => (int) $r['id']]);
        }
        $task->addVolume(count($toNotify));
        PluginGitpluginsLog::record(null, 'notify', 'ok', 'digest: ' . count($toNotify) . ' update(s)');

        return 1;
    }

    /**
     * PURE: a stable signature of a row's "available set" — what we compare against
     * last_notified_sha to decide whether the same digest was already sent.
     * available_sha when present (most precise), else available_version.
     *
     * @param array<string,mixed> $row
     */
    public static function availSignature(array $row): string
    {
        $sha = strtolower(trim((string) ($row['available_sha'] ?? '')));
        if ($sha !== '') {
            return $sha;
        }

        return strtolower(trim((string) ($row['available_version'] ?? '')));
    }

    /**
     * PURE: should this flagged row be (re-)included in a digest? Yes when it has
     * an available update AND its current available signature differs from the one
     * last e-mailed (anti-spam — same set never spammed twice).
     *
     * @param array<string,mixed> $row
     */
    public static function shouldNotifyRow(array $row): bool
    {
        if ((int) ($row['update_available'] ?? 0) !== 1) {
            return false;
        }
        $sig = self::availSignature($row);
        if ($sig === '') {
            return false;
        }

        return $sig !== strtolower(trim((string) ($row['last_notified_sha'] ?? '')));
    }

    /**
     * Digest recipient addresses (validated, CR/LF-safe). Order:
     *   1. explicit config override (notify_recipient), if a valid address;
     *   2. else every active Super-Admin profile user's default email;
     *   3. else GLPI's admin_email.
     * Reads server-side only (A01) — no client input. De-duplicated.
     *
     * @return string[]
     */
    public static function digestRecipients(PluginGitpluginsConfig $cfg): array
    {
        /** @var DBmysql $DB */
        global $DB;
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $override = self::cleanEmail($cfg->getNotifyRecipient());
        if ($override !== '') {
            return [$override];
        }

        $out = [];
        // Super-Admin users (profile id 4 in stock GLPI, but resolve by name to be
        // safe) → their default, non-empty e-mail addresses.
        try {
            $iter = $DB->request([
                'SELECT'     => ['glpi_useremails.email AS email'],
                'FROM'       => 'glpi_profiles_users',
                'INNER JOIN' => [
                    'glpi_profiles' => [
                        'ON' => ['glpi_profiles_users' => 'profiles_id', 'glpi_profiles' => 'id'],
                    ],
                    'glpi_users' => [
                        'ON' => ['glpi_profiles_users' => 'users_id', 'glpi_users' => 'id'],
                    ],
                    'glpi_useremails' => [
                        'ON' => ['glpi_useremails' => 'users_id', 'glpi_users' => 'id'],
                    ],
                ],
                'WHERE' => [
                    'glpi_profiles.name'    => 'Super-Admin',
                    'glpi_users.is_active'  => 1,
                    'glpi_users.is_deleted' => 0,
                    'glpi_useremails.is_default' => 1,
                ],
            ]);
            foreach ($iter as $r) {
                $e = self::cleanEmail((string) ($r['email'] ?? ''));
                if ($e !== '') {
                    $out[$e] = $e;
                }
            }
        } catch (\Throwable $e) {
            // Query shape varies across GLPI minor versions — fall back below.
        }

        if ($out === []) {
            $admin = self::cleanEmail((string) ($CFG_GLPI['admin_email'] ?? ''));
            if ($admin !== '') {
                $out[$admin] = $admin;
            }
        }

        return array_values($out);
    }

    /** Validate + CR/LF-strip an e-mail address (header-injection guard). '' if invalid. */
    private static function cleanEmail(string $email): string
    {
        $email = str_replace(["\r", "\n", "\0"], '', trim($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * PURE: build the digest [subject, plain-text body] from the rows to notify.
     * Lists plugin key, installed → available, and the repo URL when known. No
     * secrets/tokens (rows carry none); CR/LF stripped from every interpolated
     * value (header/body-injection guard).
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array{0:string,1:string}
     */
    public static function buildDigest(array $rows): array
    {
        $n       = count($rows);
        $subject = str_replace(["\r", "\n"], '', sprintf(
            _n('%d managed plugin has an update available', '%d managed plugins have updates available', $n, 'gitplugins'),
            $n
        ));

        $lines = [__('The following git-managed plugins have an update available:', 'gitplugins'), ''];
        foreach ($rows as $r) {
            $key   = self::scrub((string) ($r['plugin_key'] ?? ''));
            $inst  = self::scrub((string) ($r['installed_version'] ?? '')) ?: '—';
            $avail = self::scrub((string) ($r['available_version'] ?? '')) ?: self::scrub((string) ($r['available_sha'] ?? '')) ?: '—';
            $repo  = self::scrub((string) ($r['repo'] ?? ''));
            $line  = sprintf('• %s: %s → %s', $key, $inst, $avail);
            if ($repo !== '') {
                $line .= '  (' . $repo . ')';
            }
            $lines[] = $line;
        }
        $lines[] = '';
        $lines[] = __('Review and apply updates from the Git Plugin Installer status page.', 'gitplugins');

        return [$subject, implode("\n", $lines)];
    }

    /** Strip CR/LF/NUL from a value before it goes into an e-mail. */
    private static function scrub(string $v): string
    {
        return str_replace(["\r", "\n", "\0"], '', trim($v));
    }

    /**
     * Send the digest to each recipient via GLPI's mailer (inherits SMTP config).
     * Live-box only. Returns true if at least one message was accepted for sending.
     *
     * @param string[] $recipients
     */
    private static function sendDigest(array $recipients, string $subject, string $body): bool
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $sent = false;
        $from = (string) ($CFG_GLPI['admin_email'] ?? '');
        foreach ($recipients as $to) {
            try {
                $mailer = new \GLPIMailer();
                $email  = $mailer->getEmail();
                if ($from !== '') {
                    $email->from($from);
                }
                $email->to($to);
                $email->subject($subject);
                $email->text($body);
                if ($mailer->send()) {
                    $sent = true;
                }
            } catch (\Throwable $e) {
                // Generic failure — never surface SMTP detail; try the next address.
            }
        }

        return $sent;
    }

    /**
     * Resolve the ref + (optional) SHA to act on for a source, honouring its ref
     * policy. Network step (SSRF-guarded) only for latest_tag; the pinned policies
     * are pure. Returns ['ref'=>..,'version'=>..,'sha'=>..].
     *
     * @return array{ref:string,version:string,sha:string}
     */
    public static function resolveLatest(array $src, PluginGitpluginsConfig $cfg): array
    {
        $policy   = (string) ($src['ref_policy'] ?? 'latest_tag');
        $ref      = (string) ($src['ref'] ?? '');
        $provider = (string) ($src['provider'] ?? 'unknown');
        $url      = (string) ($src['url'] ?? '');

        // LOCAL source (Phase 1): no network. The "available" version is whatever
        // the local working tree's plugin.xml declares (best-effort). Gated by the
        // same feature-flag + allowlist as the install path.
        if ($provider === 'local') {
            return ['ref' => 'local', 'version' => self::localVersion($url, $cfg), 'sha' => ''];
        }

        if ($policy === 'pin_sha' || $policy === 'pin_tag') {
            return [
                'ref'     => $ref,
                'version' => PluginGitpluginsVersion::normalise($ref),
                'sha'     => $policy === 'pin_sha' ? $ref : '',
            ];
        }

        // track_branch → resolve the branch's CURRENT head SHA (SSRF-guarded) so
        // we can tell whether the branch has moved since the last install. The ref
        // (branch name) stays the fetch target; only the SHA drives update detection.
        if ($policy === 'track_branch') {
            return [
                'ref'     => $ref,
                'version' => PluginGitpluginsVersion::normalise($ref),
                'sha'     => self::fetchBranchSha($provider, $url, $ref, $cfg),
            ];
        }

        // release → a built tarball published as a release asset. The actual
        // asset URL + resolved tag are resolved at fetch time (installer, network)
        // from the releases API; here we just carry the (optional) pinned tag so
        // an empty ref means "latest release". Version is best-effort.
        if ($policy === 'release') {
            return [
                'ref'     => $ref,
                'version' => $ref !== '' ? PluginGitpluginsVersion::normalise($ref) : '',
                'sha'     => '',
            ];
        }

        // latest_tag → query the host tags API (SSRF-guarded), pick the highest.
        $tags = self::fetchTags($provider, $url, $cfg);
        $best = PluginGitpluginsVersion::highest($tags);
        if ($best === null) {
            return ['ref' => $ref, 'version' => '', 'sha' => ''];
        }

        return ['ref' => $best, 'version' => PluginGitpluginsVersion::normalise($best), 'sha' => ''];
    }

    /**
     * Read the declared version from a LOCAL source's plugin.xml (Phase 1).
     * Filesystem read, gated by allow_local_sources + the path allowlist; returns
     * '' when disabled, out of allowlist, missing, or unparseable.
     */
    private static function localVersion(string $path, PluginGitpluginsConfig $cfg): string
    {
        if (!$cfg->allowLocalSources()
            || !PluginGitpluginsLocalsource::pathAllowed($path, $cfg->getLocalSourceRoots())) {
            return '';
        }
        $real = @realpath($path);
        if ($real === false || !is_file($real . '/plugin.xml')) {
            return '';
        }
        $xml = @file_get_contents($real . '/plugin.xml');
        if (!is_string($xml)) {
            return '';
        }
        $info = PluginGitpluginsManifest::parseInfo($xml);

        return is_array($info) ? (string) ($info['version'] ?? '') : '';
    }

    /**
     * Fetch the tag names from a host's API (SSRF-guarded, proxy-aware). Live-box
     * only; returns [] on any failure (treated as "no update info").
     *
     * @return string[]
     */
    private static function fetchTags(string $provider, string $url, PluginGitpluginsConfig $cfg): array
    {
        $api = PluginGitpluginsRefResolver::tagsApiUrl($provider, $url);
        if ($api === null) {
            return [];
        }
        try {
            PluginGitpluginsFetcher::assertSafeUrl($api, $cfg->getAllowedHosts());
            $client = \Toolbox::getGuzzleClient();
            $resp   = $client->request('GET', $api, [
                'headers' => ['User-Agent' => 'GLPI-gitplugins', 'Accept' => 'application/json'],
                'timeout' => $cfg->getFetchTimeoutSeconds(),
            ]);
            $data = json_decode((string) $resp->getBody(), true);
            if (!is_array($data)) {
                return [];
            }
            $tags = [];
            foreach ($data as $entry) {
                // GitHub/Gitea/Forgejo: {name: "v1.2.3"}; GitLab: {name: "v1.2.3"}.
                if (is_array($entry) && !empty($entry['name'])) {
                    $tags[] = (string) $entry['name'];
                }
            }

            return $tags;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Resolve a branch's current HEAD commit SHA from the host API (SSRF-guarded,
     * proxy-aware). Live-box only; returns '' on any failure (treated as "no SHA",
     * which falls back to version comparison — never a false "update available").
     */
    private static function fetchBranchSha(string $provider, string $url, string $branch, PluginGitpluginsConfig $cfg): string
    {
        if ($branch === '') {
            return '';
        }
        $api = PluginGitpluginsRefResolver::branchShaApiUrl($provider, $url, $branch);
        if ($api === null) {
            return '';
        }
        try {
            PluginGitpluginsFetcher::assertSafeUrl($api, $cfg->getAllowedHosts());
            $client = \Toolbox::getGuzzleClient();
            $resp   = $client->request('GET', $api, [
                'headers' => ['User-Agent' => 'GLPI-gitplugins', 'Accept' => 'application/json'],
                'timeout' => $cfg->getFetchTimeoutSeconds(),
            ]);
            $data = json_decode((string) $resp->getBody(), true);

            return is_array($data) ? PluginGitpluginsRefResolver::branchSha($data) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }
}
