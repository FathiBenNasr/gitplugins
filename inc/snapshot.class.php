<?php
/**
 * Git Plugin Installer — DB-table snapshot before migration (R8).
 *
 * R2 restores the plugin's FILES; a failed migration can still leave the
 * plugin's SCHEMA ahead of the restored code. Full rollback needs the plugin's
 * OWN tables saved too. Before nativeInstall we gzip-dump every
 * glpi_plugin_<key>* table to GLPI's dump dir (outside the web tree); on an R3
 * rollback we restore them. Strictly scoped — it only ever touches the target
 * plugin's own prefixed tables (never core, never a sibling plugin). Bounded by
 * a row/size cap: over cap we skip + warn rather than block the update. The
 * table-enumeration + cap decision are PURE (unit-tested); dump/restore are
 * integration-gated.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsSnapshot
{
    /**
     * PURE: the subset of $allTables owned by plugin $key — exactly
     * `glpi_plugin_<key>` and `glpi_plugin_<key>_*`. An invalid key yields the
     * empty set (never a broad match). No DB access — the table list is passed
     * in so this is unit-testable.
     *
     * @param string[] $allTables
     * @return string[]
     */
    public static function ownedTables(array $allTables, string $key, array $otherKeys = []): array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            return [];
        }
        $exact  = 'glpi_plugin_' . $key;
        $prefix = $exact . '_';
        // WHY: `glpi_plugin_foo_` is also the prefix of a sibling plugin `foo_x`'s
        // tables. A table that belongs to a longer installed key is that plugin's,
        // never ours — snapshotting/restoring it would overwrite a sibling's data.
        $siblingPrefixes = [];
        foreach ($otherKeys as $other) {
            $other = (string) $other;
            if ($other !== $key && str_starts_with($other, $key . '_') && preg_match('/^[a-z0-9_]+$/', $other)) {
                $siblingPrefixes[] = 'glpi_plugin_' . $other;
            }
        }
        $out = [];
        foreach ($allTables as $t) {
            $t = (string) $t;
            if ($t !== $exact && strncmp($t, $prefix, strlen($prefix)) !== 0) {
                continue;
            }
            foreach ($siblingPrefixes as $sp) {
                if ($t === $sp || str_starts_with($t, $sp . '_')) {
                    continue 2;
                }
            }
            $out[$t] = $t;
        }

        return array_values($out);
    }

    /**
     * PURE: is every statement of a dump confined to the plugin's own tables?
     * Only the exact statement shapes dumpTable() writes are accepted — a
     * `DROP TABLE IF EXISTS`, `CREATE TABLE` or `INSERT INTO` naming one of
     * $owned, plus the FOREIGN_KEY_CHECKS toggles. Anything else (a write to a
     * core table, a GRANT, a second statement on the same line…) refuses the
     * whole file.
     *
     * @param string[] $statements as split by splitStatements()
     * @param string[] $owned      the tables restore() may touch
     */
    public static function statementsAreOwned(array $statements, array $owned): bool
    {
        if ($statements === []) {
            return false;
        }
        $names = [];
        foreach ($owned as $t) {
            if (preg_match('/^[a-z0-9_]+$/', (string) $t)) {
                $names[] = preg_quote((string) $t, '/');
            }
        }
        if ($names === []) {
            return false;
        }
        $tables = '`(?:' . implode('|', $names) . ')`';
        $shape  = '/^(?:SET FOREIGN_KEY_CHECKS=[01]'
            . '|DROP TABLE IF EXISTS ' . $tables
            . '|CREATE TABLE ' . $tables . ' \\(.*'
            . '|INSERT INTO ' . $tables . ' \\(.*)$/sD';
        foreach ($statements as $stmt) {
            $stmt = (string) $stmt;
            if (preg_match($shape, $stmt) !== 1) {
                return false;
            }
            // WHY the skeleton: the shape above only pins the statement's head.
            // `INSERT INTO <owned> (...) SELECT ... FROM <core table>` or
            // `CREATE TABLE <owned> (...) SELECT ...` match it while reading
            // core data. With every quoted value and identifier collapsed, what
            // is left must be exactly what dumpTable() writes: one statement
            // (no `;`), no double-quoted string, an INSERT made of a column list
            // and literal values only, and no SELECT anywhere.
            $skel = self::skeleton($stmt);
            if ($skel === null || str_contains($skel, ';') || preg_match('/\bSELECT\b/i', $skel) === 1) {
                return false;
            }
            if (str_starts_with($stmt, 'INSERT INTO ')
                && preg_match("/^INSERT INTO `` \\(``(?:,``)*\\) VALUES \\((?:''|NULL)(?:,(?:''|NULL))*\\)$/D", $skel) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * PURE: $stmt with every single-quoted value collapsed to `''` and every
     * backtick identifier to an empty pair of backticks, or null when a quote is
     * left open or a double quote appears outside them (dumpTable() never
     * writes one; MySQL would read it as a string the scan cannot see).
     */
    public static function skeleton(string $stmt): ?string
    {
        $out = '';
        $len = strlen($stmt);
        for ($i = 0; $i < $len; $i++) {
            $c = $stmt[$i];
            if ($c === "'" || $c === '`') {
                $closed = false;
                for ($i++; $i < $len; $i++) {
                    $d = $stmt[$i];
                    if ($c === "'" && $d === '\\') {
                        $i++; // escaped char inside a quoted value
                    } elseif ($d === $c) {
                        if ($i + 1 < $len && $stmt[$i + 1] === $c) {
                            $i++; // doubled quote: still inside
                        } else {
                            $closed = true;
                            break;
                        }
                    }
                }
                if (!$closed) {
                    return null;
                }
                $out .= $c . $c;
            } elseif ($c === '"') {
                return null;
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    /**
     * PURE: table names that appear in DROP/CREATE/INSERT statements, so a table
     * the dump recreates (dropped by a failed migration) is still recognised as
     * owned when it no longer exists in the live schema.
     *
     * @param string[] $statements
     * @return string[]
     */
    public static function tablesNamedIn(array $statements): array
    {
        $out = [];
        foreach ($statements as $stmt) {
            if (preg_match('/^(?:DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([a-z0-9_]+)`/', (string) $stmt, $m) === 1) {
                $out[$m[1]] = $m[1];
            }
        }

        return array_values($out);
    }

    /**
     * PURE: is $realPath a file directly inside $realDir? Both are expected to be
     * realpath()-resolved by the caller (so a symlink cannot point outside).
     */
    public static function isInsideDir(string $realPath, string $realDir): bool
    {
        if ($realPath === '' || $realDir === '' || $realDir === '/') {
            return false;
        }

        return dirname($realPath) === rtrim($realDir, '/');
    }

    /**
     * PURE: is a snapshot within the configured size budget? Total bytes vs the
     * cap in MB (0/negative cap = unlimited). Extracted so the skip-and-warn
     * decision is unit-tested without a real dump.
     */
    public static function withinBudget(int $totalBytes, int $capMb): bool
    {
        if ($capMb <= 0) {
            return true;
        }

        return $totalBytes <= $capMb * 1024 * 1024;
    }

    /**
     * Dump the plugin's owned tables to a gzipped .sql.gz in GLPI's dump dir
     * (live-box: DB + FS). Returns the dump path, or null when there is nothing
     * to dump / over the size cap / on any error (best-effort — never blocks the
     * update). The estimated size is checked against the cap BEFORE writing.
     */
    public static function dumpOwnedTables(string $key, int $capMb = 0): ?string
    {
        try {
            return self::doDump($key, $capMb);
        } catch (\Throwable $e) {
            // Fail-safe: a snapshot failure must NEVER break an install — the R2
            // file backup already covers rollback. Swallow and skip the dump.
            return null;
        }
    }

    /** Inner dump body (see dumpOwnedTables — wrapped so any throw fails safe). */
    private static function doDump(string $key, int $capMb): ?string
    {
        /** @var DBmysql $DB */
        global $DB;

        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            return null;
        }
        $owned = self::ownedTables(self::allTables(), $key, self::installedKeys());
        if ($owned === []) {
            return null;
        }

        // Pre-flight size estimate from information_schema; skip + warn if over.
        if ($capMb > 0 && !self::withinBudget(self::estimateBytes($owned), $capMb)) {
            return null;
        }

        $dir = self::dumpDir();
        if ($dir === '' || !@is_dir($dir)) {
            return null;
        }
        $path = $dir . '/' . self::dumpFilename($key, date('YmdHis'), bin2hex(random_bytes(6)));
        $gz   = @gzopen($path, 'wb9');
        if ($gz === false) {
            return null;
        }
        try {
            gzwrite($gz, "-- gitplugins snapshot: {$key}\nSET FOREIGN_KEY_CHECKS=0;\n");
            foreach ($owned as $table) {
                self::dumpTable($DB, $gz, $table);
            }
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (\Throwable $e) {
            gzclose($gz);
            @unlink($path);

            return null;
        }
        gzclose($gz);
        @chmod($path, 0600);
        if (class_exists('PluginGitpluginsBackup')) {
            PluginGitpluginsBackup::chownWeb($path);
        }

        return $path;
    }

    /**
     * Restore a snapshot produced by dumpOwnedTables (live-box). Best-effort;
     * returns true when every statement applied.
     *
     * WHY the checks below: the dump path comes from a DB row and the file sits
     * on disk, so "it was produced scoped" is an assumption, not a guarantee.
     * Executing every statement of an arbitrary file would be an arbitrary-SQL
     * primitive. We enforce instead: the file is one of $key's snapshot files
     * directly inside GLPI's dump dir, and every statement only touches $key's
     * own tables — otherwise nothing at all is executed.
     */
    public static function restore(string $gzPath, string $key): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        if (!preg_match('/^[a-z0-9_]+$/', $key) || !is_file($gzPath)) {
            return false;
        }
        $real = realpath($gzPath);
        $dir  = realpath(self::dumpDir());
        // Accept this plugin's per-snapshot files, and the single shared name
        // used before 1.0.4 (still guarded statement by statement below).
        $base   = basename($real === false ? '' : $real);
        $legacy = rtrim(self::dumpPrefix($key), '-') . '.sql.gz';
        if ($real === false || $dir === false || !self::isInsideDir($real, $dir)
            || !(str_starts_with($base, self::dumpPrefix($key)) || $base === $legacy)) {
            return false;
        }
        $gzPath = $real;
        $sql    = '';
        $gz  = @gzopen($gzPath, 'rb');
        if ($gz === false) {
            return false;
        }
        while (!gzeof($gz)) {
            $sql .= gzread($gz, 1 << 20);
        }
        gzclose($gz);
        if ($sql === '') {
            return false;
        }
        $statements = self::splitStatements($sql);
        $owned      = self::ownedTables(
            array_merge(self::allTables(), self::tablesNamedIn($statements)),
            $key,
            self::installedKeys()
        );
        if (!self::statementsAreOwned($statements, $owned)) {
            return false;
        }
        $ok = true;
        foreach ($statements as $stmt) {
            try {
                $DB->doQuery($stmt);
            } catch (\Throwable $e) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * PURE: split a dump into individual statements. Full-line `--` comments are
     * stripped FIRST (so a comment line preceding a real statement doesn't shadow
     * it), then split on a `;` at end of line.
     */
    public static function splitStatements(string $sql): array
    {
        // Drop whole-line comments (leading whitespace tolerated).
        $sql = preg_replace('/^[ \t]*--[^\n]*\n/m', '', $sql) ?? $sql;
        $out = [];
        foreach (preg_split('/;\s*\n/', $sql) ?: [] as $s) {
            $s = trim($s);
            if ($s !== '') {
                $out[] = $s;
            }
        }

        return $out;
    }

    /**
     * PURE: snapshot filename — one file PER snapshot. WHY: retained snapshot
     * rows each point at their own dump; a per-plugin name let each new version
     * overwrite the previous dump (a rollback to N-2 restored N-1's schema) and
     * let prune() unlink the dump the newest snapshot still needed.
     */
    public static function dumpFilename(string $key, string $stamp, string $rand): string
    {
        $s = preg_replace('/[^0-9]+/', '', $stamp) ?? '';
        $r = preg_replace('/[^a-f0-9]+/', '', strtolower($rand)) ?? '';

        return self::dumpPrefix($key) . ($s !== '' ? $s : '0') . '-' . ($r !== '' ? $r : '0') . '.sql.gz';
    }

    /** PURE: the filename prefix shared by every snapshot of one plugin. */
    public static function dumpPrefix(string $key): string
    {
        return 'gitplugins-snap-' . (preg_replace('/[^a-z0-9_]+/', '_', strtolower($key)) ?? 'plugin') . '-';
    }

    /** Live: every table name of the GLPI database. */
    private static function allTables(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $tables = [];
        foreach ($DB->listTables() as $row) {
            $tables[] = is_array($row) ? (string) reset($row) : (string) $row;
        }

        return $tables;
    }

    /** Live: plugin keys known to GLPI (READ glpi_plugins), for the sibling-prefix rule. */
    private static function installedKeys(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $keys = [];
        try {
            foreach ($DB->request(['SELECT' => ['directory'], 'FROM' => 'glpi_plugins']) as $r) {
                $keys[] = (string) ($r['directory'] ?? '');
            }
        } catch (\Throwable $e) {
            // best-effort: without the list we simply cannot exclude siblings
        }

        return $keys;
    }

    /** GLPI dump dir (outside the web tree), or '' when unknown. */
    private static function dumpDir(): string
    {
        if (defined('GLPI_DUMP_DIR')) {
            return (string) GLPI_DUMP_DIR;
        }
        if (defined('GLPI_VAR_DIR')) {
            return (string) GLPI_VAR_DIR . '/_dump';
        }

        return '';
    }

    /** Sum of data+index bytes for the given tables (0 when unknown). */
    private static function estimateBytes(array $tables): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $total = 0;
        foreach ($tables as $t) {
            try {
                $iter = $DB->request([
                    'SELECT' => ['DATA_LENGTH', 'INDEX_LENGTH'],
                    'FROM'   => 'information_schema.TABLES',
                    'WHERE'  => ['TABLE_SCHEMA' => new \QueryExpression('DATABASE()'), 'TABLE_NAME' => $t],
                ]);
                foreach ($iter as $r) {
                    $total += (int) ($r['DATA_LENGTH'] ?? 0) + (int) ($r['INDEX_LENGTH'] ?? 0);
                }
            } catch (\Throwable $e) {
                // ignore — estimate is best-effort
            }
        }

        return $total;
    }

    /** Write DROP/CREATE + INSERTs for one table to the gz stream. */
    private static function dumpTable(DBmysql $DB, $gz, string $table): void
    {
        $create = $DB->doQuery('SHOW CREATE TABLE ' . $DB->quoteName($table));
        $row    = $create ? $DB->fetchAssoc($create) : null;
        $ddl    = is_array($row) ? ($row['Create Table'] ?? '') : '';
        if ($ddl === '') {
            return;
        }
        gzwrite($gz, "DROP TABLE IF EXISTS " . $DB->quoteName($table) . ";\n");
        gzwrite($gz, $ddl . ";\n");

        $iter = $DB->request(['FROM' => $table]);
        foreach ($iter as $r) {
            $cols = array_map([$DB, 'quoteName'], array_keys($r));
            $vals = array_map(static fn($v) => $v === null ? 'NULL' : $DB->quoteValue((string) $v), array_values($r));
            gzwrite($gz, 'INSERT INTO ' . $DB->quoteName($table)
                . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n");
        }
    }
}
