<?php
/**
 * Git Plugin Installer — conflict / known-issues registry (Phase 7).
 *
 * A curated dataset of known-bad plugin combinations (shipped JSON seed,
 * refreshable from the catalog in Phase 10) keyed by plugin_key + an optional
 * version range, listing conflicts with peer plugins, minimum peer versions, and
 * free-text advisories (e.g. "geninventorynumber ≤ x blocks native NetInventory").
 * Consulted at install-confirm and surfaced on status.
 *
 * The range match + issue resolution (matchRange / evaluate) are PURE and
 * table-tested; load()/seed() are the DB-backed I/O (writes ONLY our own
 * glpi_plugin_gitplugins_known_issues table).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsKnownissues
{
    /** Valid issue kinds (mirrors the ENUM in hook.php). */
    public const KINDS = ['conflict', 'advisory', 'min_peer'];

    /**
     * PURE: does a version satisfy a range expression?
     *
     * Grammar (subset of the npm/composer style, whitespace = logical AND):
     *   ''  or  '*'            → any version
     *   '1.2.0 - 2.0.0'        → >=1.2.0 AND <=2.0.0  (hyphen range)
     *   '>=1.0.0 <2.0.0'       → each token ANDed
     *   tokens: >= <= > < = == != , or a bare version (treated as ==)
     *
     * An empty $version only satisfies the "any" range (conservative — never
     * assert a bounded constraint against an unknown installed version, so we
     * don't raise a false conflict).
     */
    public static function matchRange(string $version, string $range): bool
    {
        $range   = trim($range);
        $version = PluginGitpluginsVersion::normalise($version);
        if ($range === '' || $range === '*') {
            return true;
        }
        if ($version === '') {
            return false;
        }

        // Hyphen range "A - B" (spaces around the dash are required, per convention).
        if (preg_match('/^(\S+)\s+-\s+(\S+)$/', $range, $m) === 1) {
            return version_compare($version, PluginGitpluginsVersion::normalise($m[1]), '>=')
                && version_compare($version, PluginGitpluginsVersion::normalise($m[2]), '<=');
        }

        foreach (preg_split('/\s+/', $range) ?: [] as $token) {
            if ($token === '') {
                continue;
            }
            if (!self::matchComparator($version, $token)) {
                return false;
            }
        }

        return true;
    }

    /** PURE: match one comparator token (">=1.2.0", "!=1.0", "1.2.3"=="=="). */
    private static function matchComparator(string $version, string $token): bool
    {
        if (preg_match('/^(>=|<=|==|!=|=|>|<)\s*(.+)$/', $token, $m) === 1) {
            $op  = $m[1] === '=' ? '==' : $m[1];
            $rhs = PluginGitpluginsVersion::normalise($m[2]);
            if ($rhs === '') {
                return true;
            }

            return version_compare($version, $rhs, $op);
        }

        // Bare version → exact match.
        return version_compare($version, PluginGitpluginsVersion::normalise($token), '==');
    }

    /**
     * PURE: which curated issues apply to a plugin at a version, given the set of
     * currently-installed active peers (peerKey => peerVersion)?
     *
     *  - advisory : applies whenever plugin_key + version_range match.
     *  - conflict : applies when the peer is installed AND its version is inside
     *               peer_range (empty peer_range = any installed version).
     *  - min_peer : applies when the peer IS installed but its version does NOT
     *               satisfy peer_range (peer present but too old/new).
     *
     * plugin_key '*' matches any plugin (a global advisory). Unknown kinds are
     * ignored (forward-compatible with a newer catalog).
     *
     * @param array<int,array<string,mixed>> $issues
     * @param array<string,string>           $peers  installed peerKey => version
     * @return array<int,array{kind:string,peer_key:string,message:string}>
     */
    public static function evaluate(array $issues, string $key, string $version, array $peers): array
    {
        $key = trim($key);
        $out = [];
        foreach ($issues as $issue) {
            $ikey = trim((string) ($issue['plugin_key'] ?? ''));
            if ($ikey !== '*' && strcasecmp($ikey, $key) !== 0) {
                continue;
            }
            if (!self::matchRange($version, (string) ($issue['version_range'] ?? ''))) {
                continue;
            }
            $kind = (string) ($issue['kind'] ?? '');
            if (!in_array($kind, self::KINDS, true)) {
                continue;
            }
            $peerKey   = trim((string) ($issue['peer_key'] ?? ''));
            $peerRange = (string) ($issue['peer_range'] ?? '');

            if ($kind === 'conflict') {
                if ($peerKey === '' || !array_key_exists($peerKey, $peers)) {
                    continue;
                }
                if (!self::matchRange((string) $peers[$peerKey], $peerRange)) {
                    continue;
                }
            } elseif ($kind === 'min_peer') {
                // Only meaningful when the peer is present but out of the required
                // range; a missing peer is not this registry's concern.
                if ($peerKey === '' || !array_key_exists($peerKey, $peers)) {
                    continue;
                }
                if (self::matchRange((string) $peers[$peerKey], $peerRange)) {
                    continue;
                }
            }

            $out[] = [
                'kind'     => $kind,
                'peer_key' => $peerKey,
                'message'  => trim((string) ($issue['message'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * PURE: sanitise a raw issue record (from JSON or DB) into a normalised row,
     * or null if it is unusable (no plugin_key, invalid kind). Keeps the dataset
     * loader and the seeder honest.
     *
     * @param array<string,mixed> $raw
     * @return array{plugin_key:string,version_range:string,kind:string,peer_key:string,peer_range:string,message:string,source:string}|null
     */
    public static function normaliseIssue(array $raw): ?array
    {
        $key  = trim((string) ($raw['plugin_key'] ?? ''));
        $kind = strtolower(trim((string) ($raw['kind'] ?? '')));
        if ($key === '' || !in_array($kind, self::KINDS, true)) {
            return null;
        }

        return [
            'plugin_key'    => mb_substr($key, 0, 64),
            'version_range' => mb_substr(trim((string) ($raw['version_range'] ?? '')), 0, 64),
            'kind'          => $kind,
            'peer_key'      => mb_substr(trim((string) ($raw['peer_key'] ?? '')), 0, 64),
            'peer_range'    => mb_substr(trim((string) ($raw['peer_range'] ?? '')), 0, 64),
            'message'       => mb_substr(trim((string) ($raw['message'] ?? '')), 0, 255),
            'source'        => mb_substr(trim((string) ($raw['source'] ?? 'builtin')), 0, 64),
        ];
    }

    /**
     * Live: consult the registry for one plugin at a version against the set of
     * installed active peers. Reads our known_issues table (DB); returns the
     * applicable warnings. Never throws into the caller.
     *
     * @return array<int,array{kind:string,peer_key:string,message:string}>
     */
    public static function warningsFor(string $key, string $version): array
    {
        try {
            $issues = self::load();
            $peers  = self::installedPeers();

            return self::evaluate($issues, $key, $version, $peers);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Load the registry rows from the DB table. Live-box. Returns [] on any error.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function load(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        if (!$DB->tableExists('glpi_plugin_gitplugins_known_issues')) {
            return [];
        }
        $out = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_gitplugins_known_issues']) as $r) {
            $out[] = $r;
        }

        return $out;
    }

    /**
     * Read the installed + active plugins from core glpi_plugins (READ-only) as a
     * peerKey => version map, for conflict evaluation. Live-box.
     *
     * @return array<string,string>
     */
    public static function installedPeers(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $out       = [];
        $activated = defined('Plugin::ACTIVATED') ? Plugin::ACTIVATED : 1;
        try {
            foreach ($DB->request([
                'SELECT' => ['directory', 'version'],
                'FROM'   => 'glpi_plugins',
                'WHERE'  => ['state' => $activated],
            ]) as $r) {
                $dir = (string) ($r['directory'] ?? '');
                if ($dir !== '') {
                    $out[$dir] = (string) ($r['version'] ?? '');
                }
            }
        } catch (\Throwable $e) {
            // best-effort
        }

        return $out;
    }

    /**
     * Seed / refresh the registry from a list of raw issue records (the shipped
     * JSON on install, or the catalog later). Replaces every row whose source
     * matches the incoming records' source, so re-seeding is idempotent and never
     * clobbers an admin's manually-added rows from another source. Live-box,
     * best-effort. Returns the number of rows written.
     *
     * @param array<int,array<string,mixed>> $rawIssues
     */
    public static function seed(array $rawIssues, string $source = 'builtin'): int
    {
        /** @var DBmysql $DB */
        global $DB;

        if (!$DB->tableExists('glpi_plugin_gitplugins_known_issues')) {
            return 0;
        }
        $rows = [];
        foreach ($rawIssues as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $norm = self::normaliseIssue($raw);
            if ($norm !== null && $norm['source'] === $source) {
                $rows[] = $norm;
            }
        }

        $written = 0;
        try {
            // Replace this source's rows wholesale (idempotent refresh).
            $DB->delete('glpi_plugin_gitplugins_known_issues', ['source' => $source]);
            foreach ($rows as $row) {
                $row['date_creation'] = date('Y-m-d H:i:s');
                $DB->insert('glpi_plugin_gitplugins_known_issues', $row);
                $written++;
            }
        } catch (\Throwable $e) {
            return $written;
        }

        return $written;
    }

    /**
     * Seed the registry from the shipped JSON dataset (data/known_issues.json).
     * Called on install/update. Best-effort. Returns rows written.
     */
    public static function seedFromShipped(): int
    {
        $file = dirname(__DIR__) . '/data/known_issues.json';
        if (!is_file($file)) {
            return 0;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw)) {
            return 0;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return 0;
        }
        // Support either a bare list or {"issues":[...]}.
        $issues = isset($data['issues']) && is_array($data['issues']) ? $data['issues'] : $data;

        return self::seed(is_array($issues) ? $issues : [], 'builtin');
    }
}
