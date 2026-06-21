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
    public static function getTypeName($nb = 0): string
    {
        return __('Git plugin update check', 'gitplugins');
    }

    /** Friendly label in the Automatic Actions UI. */
    public static function cronInfo(string $name): array
    {
        return ['description' => __('Check managed git sources for updates and run pending installs', 'gitplugins')];
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
            // ----- availability check -----
            $resolved = self::resolveLatest($src, $cfg);
            $installed = PluginGitpluginsInstaller::installedVersion((string) ($src['plugin_key'] ?? ''));
            $available = $resolved['version'] ?? '';

            $DB->updateOrInsert('glpi_plugin_gitplugins_installs', [
                'plugin_key'        => (string) ($src['plugin_key'] ?? ''),
                'installed_version' => $installed,
                'available_version' => $available !== '' ? mb_substr($available, 0, 64) : null,
                'available_sha'     => !empty($resolved['sha']) ? mb_substr((string) $resolved['sha'], 0, 64) : null,
                'last_check_at'     => date('Y-m-d H:i:s'),
            ], ['plugin_gitplugins_sources_id' => (int) $sourceId]);
            $task->addVolume(1);
            $did++;

            // ----- run a pending install/update queued by the admin -----
            $row = $DB->request([
                'FROM'  => 'glpi_plugin_gitplugins_installs',
                'WHERE' => ['plugin_gitplugins_sources_id' => (int) $sourceId],
                'LIMIT' => 1,
            ])->current();
            if ($row && in_array((string) ($row['pending_action'] ?? 'none'), ['install', 'update'], true)) {
                $ref = (string) ($resolved['ref'] ?? ($src['ref'] ?? ''));
                if ($ref !== '') {
                    PluginGitpluginsInstaller::run($src, $ref, (string) ($resolved['sha'] ?? ''));
                    $did++;
                }
            }
        }

        return $did > 0 ? 1 : 0;
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

        if ($policy === 'pin_sha' || $policy === 'pin_tag' || $policy === 'track_branch') {
            return [
                'ref'     => $ref,
                'version' => PluginGitpluginsVersion::normalise($ref),
                'sha'     => $policy === 'pin_sha' ? $ref : '',
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
}
