<?php
/**
 * Pure tests for the rollback retention decision (Phase 2): given snapshot rows
 * newest-first and a keep count, prune everything past the newest N. No DB — rows
 * are injected; record/restore are integration-gated.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/rollback.class.php';

final class RollbackTest extends TestCase
{
    private static function rows(int ...$ids): array
    {
        return array_map(static fn ($id) => ['id' => $id], $ids);
    }

    public function testKeepsNewestNPrunesRest(): void
    {
        // newest-first: 10, 9, 8, 7, 6 — keep 3 → prune 7, 6.
        self::assertSame([7, 6], PluginGitpluginsRollback::idsToPrune(self::rows(10, 9, 8, 7, 6), 3));
    }

    public function testNothingToPruneWhenUnderKeep(): void
    {
        self::assertSame([], PluginGitpluginsRollback::idsToPrune(self::rows(3, 2), 3));
        self::assertSame([], PluginGitpluginsRollback::idsToPrune(self::rows(3, 2, 1), 3));
    }

    public function testKeepZeroPrunesAll(): void
    {
        self::assertSame([2, 1], PluginGitpluginsRollback::idsToPrune(self::rows(2, 1), 0));
    }

    public function testNegativeKeepTreatedAsZero(): void
    {
        self::assertSame([5], PluginGitpluginsRollback::idsToPrune(self::rows(5), -4));
    }

    /** M-15: a file still referenced by another retained snapshot is never unlinked. */
    public function testPathsToUnlinkKeepsSharedFiles(): void
    {
        $old   = ['files_archive_path' => '/b/foo-1.zip', 'db_dump_path' => '/d/gitplugins-snap-foo.sql.gz'];
        $newer = [['files_archive_path' => '/b/foo-2.zip', 'db_dump_path' => '/d/gitplugins-snap-foo.sql.gz']];
        self::assertSame(['/b/foo-1.zip'], PluginGitpluginsRollback::pathsToUnlink($old, $newer));
        // Own, unshared files both go; empty paths are ignored.
        self::assertSame(
            ['/b/foo-1.zip', '/d/a.sql.gz'],
            PluginGitpluginsRollback::pathsToUnlink(['files_archive_path' => '/b/foo-1.zip', 'db_dump_path' => '/d/a.sql.gz'], $newer)
        );
        self::assertSame([], PluginGitpluginsRollback::pathsToUnlink(['files_archive_path' => '', 'db_dump_path' => null], []));
    }

    public function testDropsZeroIds(): void
    {
        // A row with a bad/missing id must never surface as a prune target.
        self::assertSame([], PluginGitpluginsRollback::idsToPrune([['id' => 0], ['id' => 0]], 0));
    }
}
