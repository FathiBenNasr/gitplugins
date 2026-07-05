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

    public function testDropsZeroIds(): void
    {
        // A row with a bad/missing id must never surface as a prune target.
        self::assertSame([], PluginGitpluginsRollback::idsToPrune([['id' => 0], ['id' => 0]], 0));
    }
}
