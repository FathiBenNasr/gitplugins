<?php
/**
 * Git Plugin Installer — version rollback (Phase 2).
 *
 * Phase 0 already produces, on every update, an inert out-of-web-tree file
 * backup (R2) and a gzipped owned-tables DB dump (R8) of the version being
 * replaced. Instead of discarding them on success, we RETAIN the last N per
 * plugin (config `rollback_keep`) as snapshot rows, so an admin can revert a bad
 * update in one click: restore files + tables, re-register, verify. The
 * retention/prune decision is PURE (unit-tested); record/prune/restore are
 * live-box (DB + FS) and best-effort.
 *
 * Scope: only ever restores the target plugin's own files + its own prefixed
 * tables (the snapshot was produced scoped in Phase 0) — never core/siblings.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsRollback
{
    /**
     * PURE: given snapshot rows for one plugin ordered NEWEST-FIRST and a keep
     * count, return the ids to prune (everything past the newest $keep). keep<=0
     * prunes all. Unit-tested.
     *
     * @param array $rows  rows with an 'id' key, newest first
     * @return int[] ids to delete
     */
    public static function idsToPrune(array $rows, int $keep): array
    {
        $keep = max(0, $keep);
        $prune = [];
        $n = 0;
        foreach ($rows as $r) {
            $n++;
            if ($n > $keep) {
                $prune[] = (int) ($r['id'] ?? 0);
            }
        }

        return array_values(array_filter($prune, static fn ($id) => $id > 0));
    }

    /**
     * Record a retained snapshot after a successful update, then prune older ones
     * beyond `rollback_keep` (deleting their files). Live-box. Best-effort — a
     * failure here never fails the update (the install already succeeded). Returns
     * the new snapshot id, or 0 when nothing was retained (keep=0 / no backup).
     */
    public static function record(
        string $key,
        int $sourceId,
        string $version,
        string $sha,
        string $filesArchivePath,
        ?string $dbDumpPath,
        int $keep
    ): int {
        /** @var DBmysql $DB */
        global $DB;

        if ($keep <= 0 || $filesArchivePath === '' || !preg_match('/^[a-z0-9_]+$/', $key)) {
            return 0; // retention disabled or nothing to keep
        }
        try {
            $DB->insert('glpi_plugin_gitplugins_snapshots', [
                'plugin_key'                   => mb_substr($key, 0, 64),
                'plugin_gitplugins_sources_id' => $sourceId > 0 ? $sourceId : null,
                'version'                      => mb_substr($version, 0, 64),
                'sha'                          => $sha !== '' ? mb_substr($sha, 0, 64) : null,
                'files_archive_path'           => mb_substr($filesArchivePath, 0, 255),
                'db_dump_path'                 => $dbDumpPath !== null && $dbDumpPath !== '' ? mb_substr($dbDumpPath, 0, 255) : null,
                'date_creation'                => date('Y-m-d H:i:s'),
            ]);
            $id = (int) $DB->insertId();
            self::prune($key, $keep);

            return $id;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Delete snapshot rows (and their on-disk files) beyond the newest $keep for
     * a plugin. Live-box, best-effort.
     */
    public static function prune(string $key, int $keep): void
    {
        /** @var DBmysql $DB */
        global $DB;

        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            return;
        }
        $rows = self::rowsFor($key);
        foreach (self::idsToPrune($rows, $keep) as $id) {
            self::deleteSnapshot($id);
        }
    }

    /**
     * Restore a retained snapshot: files first (R2), then the owned tables (R8),
     * then re-register + verify the restored version. Live-box. Returns true only
     * when the plugin is active+versioned afterwards. Scoped strictly to $key.
     */
    public static function to(int $snapshotId, string $pluginsBase): bool
    {
        /** @var DBmysql $DB */
        global $DB;

        $row = $DB->request([
            'FROM'  => 'glpi_plugin_gitplugins_snapshots',
            'WHERE' => ['id' => $snapshotId],
            'LIMIT' => 1,
        ])->current();
        if ($row === null) {
            return false;
        }
        $key   = (string) ($row['plugin_key'] ?? '');
        $files = (string) ($row['files_archive_path'] ?? '');
        $dump  = (string) ($row['db_dump_path'] ?? '');
        if (!preg_match('/^[a-z0-9_]+$/', $key) || $files === '' || !is_file($files)) {
            return false;
        }

        if (!PluginGitpluginsBackup::restore($files, $pluginsBase, $key)) {
            return false;
        }
        if ($dump !== '' && is_file($dump)) {
            PluginGitpluginsSnapshot::restore($dump); // best-effort schema restore
        }
        $ok = PluginGitpluginsInstaller::reinstallActive($key);
        PluginGitpluginsLog::record(
            (int) ($row['plugin_gitplugins_sources_id'] ?? 0),
            'rollback',
            $ok ? 'ok' : 'error',
            ($ok ? 'rolled back ' : 'rollback failed ') . $key . ' -> ' . (string) ($row['version'] ?? '')
        );

        return $ok;
    }

    /**
     * Retained snapshots for a plugin, NEWEST FIRST (for the status UI).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function rowsFor(string $key): array
    {
        /** @var DBmysql $DB */
        global $DB;

        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            return [];
        }
        $out = [];
        foreach ($DB->request([
            'FROM'  => 'glpi_plugin_gitplugins_snapshots',
            'WHERE' => ['plugin_key' => $key],
            'ORDER' => 'id DESC',
        ]) as $r) {
            $out[] = $r;
        }

        return $out;
    }

    /** Delete every snapshot's files + rows (uninstall cleanup). Best-effort. */
    public static function purgeAll(\DBmysql $DB): void
    {
        if (!$DB->tableExists('glpi_plugin_gitplugins_snapshots')) {
            return;
        }
        foreach ($DB->request(['FROM' => 'glpi_plugin_gitplugins_snapshots']) as $r) {
            self::unlinkFiles($r);
        }
    }

    /** Delete one snapshot's files and row. Best-effort. */
    private static function deleteSnapshot(int $id): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $row = $DB->request([
            'FROM'  => 'glpi_plugin_gitplugins_snapshots',
            'WHERE' => ['id' => $id],
            'LIMIT' => 1,
        ])->current();
        if ($row !== null) {
            self::unlinkFiles($row);
        }
        try {
            $DB->delete('glpi_plugin_gitplugins_snapshots', ['id' => $id]);
        } catch (\Throwable $e) {
            // best-effort
        }
    }

    /** Remove a snapshot's on-disk backup + dump files (if any). */
    private static function unlinkFiles(array $row): void
    {
        foreach ([(string) ($row['files_archive_path'] ?? ''), (string) ($row['db_dump_path'] ?? '')] as $p) {
            if ($p !== '' && is_file($p)) {
                @unlink($p);
            }
        }
    }
}
