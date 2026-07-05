<?php
/**
 * Pure tests for the hook-collision detector (Phase 6): given the subject plugin
 * key and a $PLUGIN_HOOKS-shaped map, detect() must find overlaps on the same
 * item hook + itemtype against OTHER plugins, honour the '*' (all-itemtypes)
 * wildcard, ignore non-item wiring hooks, and stay deterministic.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/hookcheck.class.php';

final class HookcheckTest extends TestCase
{
    private const HOOKS = ['pre_item_update', 'item_add', 'pre_item_add'];

    public function testDetectsSameHookSameItemtype(): void
    {
        $map = [
            'pre_item_update' => [
                'geninventorynumber' => ['Computer' => 'fnA'],
                'otherplugin'        => ['Computer' => 'fnB'],
            ],
        ];
        $c = PluginGitpluginsHookcheck::detect('geninventorynumber', $map, self::HOOKS);
        self::assertSame(1, count($c));
        self::assertSame('pre_item_update', $c[0]['hook']);
        self::assertSame('Computer', $c[0]['itemtype']);
        self::assertSame('otherplugin', $c[0]['peer']);
    }

    public function testNoCollisionOnDifferentItemtype(): void
    {
        $map = [
            'pre_item_update' => [
                'a' => ['Computer' => 'fnA'],
                'b' => ['Ticket' => 'fnB'],
            ],
        ];
        self::assertSame([], PluginGitpluginsHookcheck::detect('a', $map, self::HOOKS));
    }

    public function testWildcardEntryCollidesWithSpecificItemtype(): void
    {
        // A bare callable (no itemtype keying) applies to every itemtype → '*'.
        $map = [
            'item_add' => [
                'a' => 'globalHandler',       // '*'
                'b' => ['Computer' => 'fnB'],
            ],
        ];
        $c = PluginGitpluginsHookcheck::detect('a', $map, self::HOOKS);
        self::assertSame(1, count($c));
        self::assertSame('Computer', $c[0]['itemtype']);
        self::assertSame('b', $c[0]['peer']);
    }

    public function testTwoWildcardsCollideOnStar(): void
    {
        $map = ['item_add' => ['a' => 'h1', 'b' => 'h2']];
        $c = PluginGitpluginsHookcheck::detect('a', $map, self::HOOKS);
        self::assertSame(1, count($c));
        self::assertSame('*', $c[0]['itemtype']);
    }

    public function testSubjectNotRegisteredYieldsNothing(): void
    {
        $map = ['pre_item_update' => ['b' => ['Computer' => 'fnB']]];
        self::assertSame([], PluginGitpluginsHookcheck::detect('a', $map, self::HOOKS));
    }

    public function testHookNotInInspectedSetIsIgnored(): void
    {
        // config_page overlap is legitimate wiring, never inspected.
        $map = ['config_page' => ['a' => 'front/config.php', 'b' => 'front/config.php']];
        self::assertSame([], PluginGitpluginsHookcheck::detect('a', $map, self::HOOKS));
    }

    public function testMultiplePeersAndItemtypesDeduped(): void
    {
        $map = [
            'pre_item_update' => [
                'a' => ['Computer' => 'x', 'Ticket' => 'y'],
                'b' => ['Computer' => 'z'],
                'c' => ['Ticket' => 'w'],
            ],
        ];
        $c = PluginGitpluginsHookcheck::detect('a', $map, self::HOOKS);
        // a↔b on Computer, a↔c on Ticket → 2 collisions, deterministically ordered.
        self::assertSame(2, count($c));
        self::assertSame('Computer', $c[0]['itemtype']);
        self::assertSame('b', $c[0]['peer']);
        self::assertSame('Ticket', $c[1]['itemtype']);
        self::assertSame('c', $c[1]['peer']);
    }

    public function testEmptyItemtypeArrayRegistersNothing(): void
    {
        self::assertSame([], PluginGitpluginsHookcheck::normaliseItemtypes([]));
        self::assertSame(['*'], PluginGitpluginsHookcheck::normaliseItemtypes('fn'));
        self::assertSame(['Computer'], PluginGitpluginsHookcheck::normaliseItemtypes(['Computer' => 'fn']));
        // A list (int keys) is a plain callable array → wildcard.
        self::assertSame(['*'], PluginGitpluginsHookcheck::normaliseItemtypes(['ClassName', 'method']));
    }

    public function testFormatProducesReadableWarnings(): void
    {
        $c = [['hook' => 'pre_item_update', 'itemtype' => 'Computer', 'peer' => 'other']];
        $msgs = PluginGitpluginsHookcheck::format('mine', $c);
        self::assertSame(1, count($msgs));
        self::assertStringContainsString('mine', $msgs[0]);
        self::assertStringContainsString('other', $msgs[0]);
        self::assertStringContainsString('Computer', $msgs[0]);
    }

    public function testEmptySubjectKeyYieldsNothing(): void
    {
        $map = ['item_add' => ['' => 'h', 'b' => 'h2']];
        self::assertSame([], PluginGitpluginsHookcheck::detect('', $map, self::HOOKS));
    }
}
