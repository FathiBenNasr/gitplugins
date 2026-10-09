<?php
/**
 * Pure tests for semver compare + downgrade refusal. No GLPI bootstrap.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/version.class.php';

final class VersionTest extends TestCase
{
    public function testNormaliseStripsV(): void
    {
        self::assertSame('1.2.3', PluginGitpluginsVersion::normalise('v1.2.3'));
        self::assertSame('1.2.3', PluginGitpluginsVersion::normalise('V1.2.3'));
        self::assertSame('1.2.3', PluginGitpluginsVersion::normalise('  1.2.3 '));
    }

    public function testIsNewer(): void
    {
        self::assertTrue(PluginGitpluginsVersion::isNewer('v1.2.0', '1.1.9'));
        self::assertFalse(PluginGitpluginsVersion::isNewer('1.0.0', '1.0.0'));
        self::assertFalse(PluginGitpluginsVersion::isNewer('1.0.0', '2.0.0'));
        // pre-release ordering: dev < final
        self::assertTrue(PluginGitpluginsVersion::isNewer('1.2.0', '1.2.0-dev'));
        // empty installed → anything is newer; empty available → never newer
        self::assertTrue(PluginGitpluginsVersion::isNewer('0.1.0', ''));
        self::assertFalse(PluginGitpluginsVersion::isNewer('', '1.0.0'));
    }

    public function testHighest(): void
    {
        self::assertSame('v2.0.0', PluginGitpluginsVersion::highest(['v1.0.0', 'v2.0.0', 'v1.9.9']));
        self::assertSame('1.10.0', PluginGitpluginsVersion::highest(['1.2.0', '1.10.0', '1.9.0']));
        self::assertNull(PluginGitpluginsVersion::highest([]));
    }

    public function testHighestPrefersStableOverPreRelease(): void
    {
        self::assertSame('v1.2.0', PluginGitpluginsVersion::highest(['v1.2.0', 'v1.3.0-rc1', 'v2.0.0-beta.2', 'v1.1.0']));
        self::assertSame('1.4.0', PluginGitpluginsVersion::highest(['1.4.0', '1.5.0RC1', '1.5.0dev']));
        // Nothing stable yet: the highest pre-release is still offered.
        self::assertSame('2.0.0-rc2', PluginGitpluginsVersion::highest(['2.0.0-rc1', '2.0.0-rc2']));
        self::assertTrue(PluginGitpluginsVersion::isPreRelease('v1.0.0-alpha'));
        self::assertFalse(PluginGitpluginsVersion::isPreRelease('v1.0.0'));
    }
}
