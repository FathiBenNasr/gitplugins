<?php
/**
 * Git Plugin Installer — convergent plugin catalog (Phase 10).
 *
 * The biggest force-multiplier: a curated manifest (single JSON hosted on
 * git.convergent.tn, SSRF-allowlisted) listing our suite — key, name,
 * description, repo URL, recommended ref_policy, category, and optional
 * known_issues. gitplugins fetches + caches it; front/catalog.php renders cards
 * whose "Add source" pre-fills the normal create form. ADVISORY ONLY — every
 * install still runs the full confirm + preflight + "runs the target's own code"
 * gate; nothing here installs anything by itself.
 *
 * parseManifest()/normaliseEntry() are PURE and unit-tested; refresh()/cache()
 * are the SSRF-guarded fetch + DB cache (writes ONLY our own tables). The catalog
 * URL is re-validated against the host allowlist at fetch time.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsCatalog
{
    private const REF_POLICIES = ['track_branch', 'latest_tag', 'pin_tag', 'pin_sha', 'release'];
    private const MAX_ENTRIES  = 200;

    /**
     * PURE: parse + validate a catalog manifest JSON string into normalised
     * entries. Accepts either a bare list or `{"plugins":[...]}`. Each entry needs
     * a valid plugin_key + https repo URL; anything malformed is dropped (never
     * throws). De-duplicated by plugin_key (first wins). Capped at MAX_ENTRIES.
     *
     * @return array<int,array{plugin_key:string,name:string,url:string,ref_policy:string,category:string,description:string,known_issues:array<int,array<string,mixed>>}>
     */
    public static function parseManifest(string $json): array
    {
        $data = json_decode(trim($json), true);
        if (!is_array($data)) {
            return [];
        }
        $list = isset($data['plugins']) && is_array($data['plugins']) ? $data['plugins'] : $data;
        if (!is_array($list)) {
            return [];
        }

        $out  = [];
        $seen = [];
        foreach ($list as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $entry = self::normaliseEntry($raw);
            if ($entry === null || isset($seen[$entry['plugin_key']])) {
                continue;
            }
            $seen[$entry['plugin_key']] = true;
            $out[] = $entry;
            if (count($out) >= self::MAX_ENTRIES) {
                break;
            }
        }

        return $out;
    }

    /**
     * PURE: normalise + validate one raw catalog entry, or null when unusable.
     * The repo URL must be https with a host (defence in depth — the SSRF guard
     * re-checks at install). ref_policy is constrained to the known enum. The
     * plugin_key must be the GLPI directory-key subset [a-z0-9_].
     *
     * @param array<string,mixed> $raw
     * @return array{plugin_key:string,name:string,url:string,ref_policy:string,category:string,description:string,known_issues:array<int,array<string,mixed>>}|null
     */
    public static function normaliseEntry(array $raw): ?array
    {
        $key = strtolower(str_replace(["\r", "\n", "\0"], '', trim((string) ($raw['plugin_key'] ?? $raw['key'] ?? ''))));
        if ($key === '' || !preg_match('/^[a-z0-9_]+$/', $key)) {
            return null;
        }

        $url    = str_replace(["\r", "\n", "\0", ' '], '', trim((string) ($raw['url'] ?? $raw['repo'] ?? '')));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host   = (string) parse_url($url, PHP_URL_HOST);
        if ($scheme !== 'https' || $host === '') {
            return null;
        }

        $policy = strtolower(trim((string) ($raw['ref_policy'] ?? 'latest_tag')));
        if (!in_array($policy, self::REF_POLICIES, true)) {
            $policy = 'latest_tag';
        }

        $issues = [];
        if (isset($raw['known_issues']) && is_array($raw['known_issues'])) {
            foreach ($raw['known_issues'] as $iss) {
                if (is_array($iss)) {
                    $issues[] = $iss;
                }
            }
        }

        return [
            'plugin_key'   => mb_substr($key, 0, 64),
            'name'         => mb_substr(self::clean((string) ($raw['name'] ?? $key)), 0, 255) ?: $key,
            'url'          => mb_substr($url, 0, 255),
            'ref_policy'   => $policy,
            'category'     => mb_substr(self::clean((string) ($raw['category'] ?? '')), 0, 64),
            'description'  => mb_substr(self::clean((string) ($raw['description'] ?? '')), 0, 255),
            'known_issues' => $issues,
        ];
    }

    /** Trim + strip CR/LF/NUL from a scalar. */
    private static function clean(string $v): string
    {
        return str_replace(["\r", "\n", "\0"], '', trim($v));
    }

    /**
     * Live: fetch the configured catalog URL (SSRF-guarded), parse, and replace
     * the cache table wholesale. Also refreshes the known-issues registry from any
     * catalog-declared issues under the 'catalog' source (Phase 7 feed). Best-
     * effort — returns the number of catalog rows cached, 0 on any failure. Never
     * throws into the caller.
     */
    public static function refresh(): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $cfg = PluginGitpluginsConfig::singleton();
        $url = $cfg->getCatalogUrl();
        if ($url === '' || !$DB->tableExists('glpi_plugin_gitplugins_catalog')) {
            return 0;
        }

        try {
            // Re-validated against the host allowlist inside fetchText().
            $body    = PluginGitpluginsFetcher::fetchText($url, $cfg->getAllowedHosts(), '', 524288, $cfg->getFetchTimeoutSeconds());
            $entries = self::parseManifest($body);
            if ($entries === []) {
                return 0;
            }

            $DB->delete('glpi_plugin_gitplugins_catalog', ['id' => ['>', 0]]);
            $now      = date('Y-m-d H:i:s');
            $written  = 0;
            $catIssues = [];
            foreach ($entries as $e) {
                $DB->insert('glpi_plugin_gitplugins_catalog', [
                    'plugin_key'  => $e['plugin_key'],
                    'name'        => $e['name'],
                    'url'         => $e['url'],
                    'ref_policy'  => $e['ref_policy'],
                    'category'    => $e['category'],
                    'description' => $e['description'],
                    'updated_at'  => $now,
                ]);
                $written++;
                // Collect any catalog-declared known issues, tagging the plugin_key
                // when the entry omitted it (so a bare {kind,message} still binds).
                foreach ($e['known_issues'] as $iss) {
                    if (!isset($iss['plugin_key'])) {
                        $iss['plugin_key'] = $e['plugin_key'];
                    }
                    $iss['source'] = 'catalog';
                    $catIssues[]   = $iss;
                }
            }

            // Feed the known-issues registry from the catalog (replaces only the
            // 'catalog' source rows; leaves 'builtin' + admin rows intact).
            if (class_exists('PluginGitpluginsKnownissues')) {
                PluginGitpluginsKnownissues::seed($catIssues, 'catalog');
            }
            PluginGitpluginsLog::record(null, 'catalog', 'ok', 'refreshed catalog: ' . $written . ' plugin(s)');

            return $written;
        } catch (\Throwable $e) {
            PluginGitpluginsLog::record(null, 'catalog', 'error', 'catalog refresh failed');

            return 0;
        }
    }

    /**
     * The cached catalog rows (for front/catalog.php). Live-box; [] on any error.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function cached(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        if (!$DB->tableExists('glpi_plugin_gitplugins_catalog')) {
            return [];
        }
        $out = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_gitplugins_catalog', 'ORDER' => ['category ASC', 'plugin_key ASC']]) as $r) {
            $out[] = $r;
        }

        return $out;
    }
}
