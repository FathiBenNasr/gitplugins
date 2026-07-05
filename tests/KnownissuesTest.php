<?php
/**
 * Pure tests for the known-issues registry (Phase 7): version-range matching and
 * issue resolution against a plugin version + installed-peer set. No I/O
 * (load/seed are integration).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/version.class.php';
require_once __DIR__ . '/../inc/knownissues.class.php';

final class KnownissuesTest extends TestCase
{
    public function testMatchRangeAnyAndEmptyVersion(): void
    {
        self::assertTrue(PluginGitpluginsKnownissues::matchRange('1.2.3', ''));
        self::assertTrue(PluginGitpluginsKnownissues::matchRange('1.2.3', '*'));
        // Unknown installed version never satisfies a bounded range (no false alarm).
        self::assertFalse(PluginGitpluginsKnownissues::matchRange('', '>=1.0.0'));
        self::assertTrue(PluginGitpluginsKnownissues::matchRange('', '*'));
    }

    public function testMatchRangeComparators(): void
    {
        self::assertTrue(PluginGitpluginsKnownissues::matchRange('1.2.0', '>=1.0.0'));
        self::assertFalse(PluginGitpluginsKnownissues::matchRange('0.9.0', '>=1.0.0'));
        self::assertTrue(PluginGitpluginsKnownissues::matchRange('1.5.0', '>=1.0.0 <2.0.0'));
        self::assertFalse(PluginGitpluginsKnownissues::matchRange('2.0.0', '>=1.0.0 <2.0.0'));
        self::assertTrue(PluginGitpluginsKnownissues::matchRange('1.0.0', '!=2.0.0'));
        self::assertFalse(PluginGitpluginsKnownissues::matchRange('2.0.0', '!=2.0.0'));
    }

    public function testMatchRangeHyphenAndVPrefix(): void
    {
        self::assertTrue(PluginGitpluginsKnownissues::matchRange('1.5.0', '1.0.0 - 2.0.0'));
        self::assertFalse(PluginGitpluginsKnownissues::matchRange('2.5.0', '1.0.0 - 2.0.0'));
        // Leading-v tolerance on both sides.
        self::assertTrue(PluginGitpluginsKnownissues::matchRange('v1.2.0', '>=v1.0.0'));
    }

    public function testMatchRangeBareVersionIsExact(): void
    {
        self::assertTrue(PluginGitpluginsKnownissues::matchRange('1.2.3', '1.2.3'));
        self::assertFalse(PluginGitpluginsKnownissues::matchRange('1.2.4', '1.2.3'));
    }

    public function testEvaluateAdvisoryApplies(): void
    {
        $issues = [
            ['plugin_key' => 'geninventorynumber', 'version_range' => '', 'kind' => 'advisory', 'message' => 'careful'],
        ];
        $out = PluginGitpluginsKnownissues::evaluate($issues, 'geninventorynumber', '1.0.0', []);
        self::assertSame(1, count($out));
        self::assertSame('advisory', $out[0]['kind']);
    }

    public function testEvaluateConflictNeedsPeerInstalledInRange(): void
    {
        $issues = [
            ['plugin_key' => 'a', 'version_range' => '', 'kind' => 'conflict', 'peer_key' => 'b', 'peer_range' => '>=2.0.0', 'message' => 'clash'],
        ];
        // Peer not installed → no warning.
        self::assertSame([], PluginGitpluginsKnownissues::evaluate($issues, 'a', '1.0.0', []));
        // Peer installed but below range → no warning.
        self::assertSame([], PluginGitpluginsKnownissues::evaluate($issues, 'a', '1.0.0', ['b' => '1.5.0']));
        // Peer installed in range → warning.
        $out = PluginGitpluginsKnownissues::evaluate($issues, 'a', '1.0.0', ['b' => '2.1.0']);
        self::assertSame(1, count($out));
        self::assertSame('b', $out[0]['peer_key']);
    }

    public function testEvaluateMinPeerWarnsWhenPeerTooOld(): void
    {
        $issues = [
            ['plugin_key' => 'a', 'version_range' => '', 'kind' => 'min_peer', 'peer_key' => 'b', 'peer_range' => '>=2.0.0', 'message' => 'needs b>=2'],
        ];
        // Peer satisfies requirement → no warning.
        self::assertSame([], PluginGitpluginsKnownissues::evaluate($issues, 'a', '1.0.0', ['b' => '2.3.0']));
        // Peer present but too old → warning.
        $out = PluginGitpluginsKnownissues::evaluate($issues, 'a', '1.0.0', ['b' => '1.0.0']);
        self::assertSame(1, count($out));
        // Peer absent → not this registry's concern.
        self::assertSame([], PluginGitpluginsKnownissues::evaluate($issues, 'a', '1.0.0', []));
    }

    public function testEvaluateRespectsPluginVersionRangeAndWildcardKey(): void
    {
        $issues = [
            ['plugin_key' => 'a', 'version_range' => '<1.0.0', 'kind' => 'advisory', 'message' => 'old only'],
            ['plugin_key' => '*', 'version_range' => '', 'kind' => 'advisory', 'message' => 'global'],
        ];
        // a@1.2.0 is out of the <1.0.0 range, but the '*' global advisory applies.
        $out = PluginGitpluginsKnownissues::evaluate($issues, 'a', '1.2.0', []);
        self::assertSame(1, count($out));
        self::assertSame('global', $out[0]['message']);
    }

    public function testNormaliseIssueRejectsInvalid(): void
    {
        self::assertNull(PluginGitpluginsKnownissues::normaliseIssue(['kind' => 'advisory']));      // no key
        self::assertNull(PluginGitpluginsKnownissues::normaliseIssue(['plugin_key' => 'a', 'kind' => 'bogus']));
        $ok = PluginGitpluginsKnownissues::normaliseIssue(['plugin_key' => 'a', 'kind' => 'ADVISORY', 'message' => 'x']);
        self::assertIsArray($ok);
        self::assertSame('advisory', $ok['kind']);
        self::assertSame('builtin', $ok['source']);
    }

    public function testShippedDatasetParsesAndNormalises(): void
    {
        $raw = file_get_contents(__DIR__ . '/../data/known_issues.json');
        self::assertTrue(is_string($raw) && $raw !== '');
        $data = json_decode($raw, true);
        self::assertIsArray($data['issues']);
        foreach ($data['issues'] as $iss) {
            self::assertIsArray(PluginGitpluginsKnownissues::normaliseIssue($iss));
        }
    }
}
