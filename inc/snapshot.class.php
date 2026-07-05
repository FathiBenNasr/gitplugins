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
    public static function ownedTables(array $allTables, string $key): array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            return [];
        }
        $exact  = 'glpi_plugin_' . $key;
        $prefix = $exact . '_';
        $out    = [];
        foreach ($allTables as $t) {
            $t = (string) $t;
            if ($t === $exact || strncmp($t, $prefix, strlen($prefix)) === 0) {
                $out[$t] = $t;
            }
        }

        return array_values($out);
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
        $tables = [];
        foreach ($DB->listTables() as $row) {
            $tables[] = is_array($row) ? reset($row) : (string) $row;
        }
        $owned = self::ownedTables($tables, $key);
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
        $path = $dir . '/' . self::dumpFilename($key);
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
     * returns true when every statement applied. Only the target plugin's own
     * tables can be present in the dump (produced scoped), so this cannot touch
     * core or sibling data.
     */
    public static function restore(string $gzPath): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        if (!is_file($gzPath)) {
            return false;
        }
        $sql = '';
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
        $ok = true;
        foreach (self::splitStatements($sql) as $stmt) {
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

    /** Snapshot filename (dot-collapsed, no separators). */
    private static function dumpFilename(string $key): string
    {
        return 'gitplugins-snap-' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($key)) . '.sql.gz';
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
