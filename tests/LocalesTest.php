<?php
/**
 * Pure tests for stale-.mo detection (R7): a .po needs compiling when the .mo
 * is missing or older. Mtimes are injected, so no filesystem.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/locales.class.php';

final class LocalesTest extends TestCase
{
    public function testMissingMoNeedsCompile(): void
    {
        self::assertTrue(PluginGitpluginsLocales::poNeedsCompile(1000, null));
        self::assertTrue(PluginGitpluginsLocales::poNeedsCompile(1000, 0));
    }

    public function testStaleMoNeedsCompile(): void
    {
        self::assertTrue(PluginGitpluginsLocales::poNeedsCompile(2000, 1000));
    }

    public function testFreshMoIsSkipped(): void
    {
        self::assertFalse(PluginGitpluginsLocales::poNeedsCompile(1000, 2000));
        self::assertFalse(PluginGitpluginsLocales::poNeedsCompile(1000, 1000));
    }

    public function testUnreadablePoIsSkipped(): void
    {
        self::assertFalse(PluginGitpluginsLocales::poNeedsCompile(0, null));
    }
}
