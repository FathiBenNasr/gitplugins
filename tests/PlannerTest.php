<?php
/**
 * Pure tests for the bulk dry-run planner (Phase 9): buildPlanRow assembles the
 * action / migration / selectable flags from resolved inputs, and selectableKeys
 * filters a plan. plan()/queueBulk are integration (DB).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/version.class.php';
require_once __DIR__ . '/../inc/installer.class.php';
require_once __DIR__ . '/../inc/planner.class.php';

final class PlannerTest extends TestCase
{
    private const OK = ['ok' => true, 'blockers' => [], 'warnings' => []];

    public function testUpdateRowIsSelectableAndFlagsMigration(): void
    {
        $r = PluginGitpluginsPlanner::buildPlanRow('foo', '1.0.0', '1.2.0', self::OK, 0);
        self::assertSame('update', $r['action']);
        self::assertTrue($r['selectable']);
        self::assertTrue($r['needs_migration']);
        self::assertFalse($r['blocked']);
    }

    public function testFreshInstallSelectableNoMigration(): void
    {
        $r = PluginGitpluginsPlanner::buildPlanRow('foo', '', '1.0.0', self::OK, 0);
        self::assertSame('install', $r['action']);
        self::assertTrue($r['selectable']);
        self::assertFalse($r['needs_migration']);
    }

    public function testUpToDateRowNotSelectable(): void
    {
        $r = PluginGitpluginsPlanner::buildPlanRow('foo', '1.2.0', '1.2.0', self::OK, 0);
        self::assertSame('skip', $r['action']);
        self::assertFalse($r['selectable']);
    }

    public function testDowngradeIsBlockedNotSelectable(): void
    {
        $r = PluginGitpluginsPlanner::buildPlanRow('foo', '2.0.0', '1.0.0', self::OK, 0);
        self::assertSame('blocked_downgrade', $r['action']);
        self::assertFalse($r['selectable']);
        self::assertTrue($r['blocked']);
    }

    public function testPreflightBlockedIsNotSelectable(): void
    {
        $pf = ['ok' => false, 'blockers' => ['missing ext: snmp'], 'warnings' => []];
        $r  = PluginGitpluginsPlanner::buildPlanRow('foo', '1.0.0', '1.2.0', $pf, 0);
        // Action is still an update, but the host can't run it → not tickable.
        self::assertSame('update', $r['action']);
        self::assertFalse($r['selectable']);
        self::assertTrue($r['blocked']);
        self::assertContains('missing ext: snmp', $r['blockers']);
    }

    public function testKnownIssuesCountCarried(): void
    {
        $r = PluginGitpluginsPlanner::buildPlanRow('foo', '1.0.0', '1.2.0', self::OK, 3);
        self::assertSame(3, $r['known_issues']);
    }

    public function testSelectableKeysFiltersPlan(): void
    {
        $plan = [
            ['plugin_key' => 'a', 'selectable' => true],
            ['plugin_key' => 'b', 'selectable' => false],
            ['plugin_key' => 'c', 'selectable' => true],
            ['plugin_key' => '', 'selectable' => true],
        ];
        self::assertSame(['a', 'c'], PluginGitpluginsPlanner::selectableKeys($plan));
    }
}
