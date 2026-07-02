<?php
/**
 * Pure tests for the LOCAL source path allowlist (Phase 1): a local path is only
 * acceptable when it sits at or beneath a configured root, with `..`/symlink
 * traversal normalised away and prefix-siblings rejected. No filesystem — the
 * copy step is integration-gated.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/localsource.class.php';

final class LocalsourceTest extends TestCase
{
    private const ROOTS = ['/srv/glpi-plugins', '/home/dev/plugins'];

    public function testPathUnderRootIsAllowed(): void
    {
        self::assertTrue(PluginGitpluginsLocalsource::pathAllowed('/srv/glpi-plugins/assetreport', self::ROOTS));
        self::assertTrue(PluginGitpluginsLocalsource::pathAllowed('/home/dev/plugins/comm', self::ROOTS));
    }

    public function testRootItselfIsAllowed(): void
    {
        self::assertTrue(PluginGitpluginsLocalsource::pathAllowed('/srv/glpi-plugins', self::ROOTS));
    }

    public function testPrefixSiblingIsRejected(): void
    {
        // /srv/glpi-plugins-evil must NOT be authorised by root /srv/glpi-plugins.
        self::assertFalse(PluginGitpluginsLocalsource::pathAllowed('/srv/glpi-plugins-evil/x', self::ROOTS));
    }

    public function testTraversalIsNormalisedAndRejected(): void
    {
        self::assertFalse(PluginGitpluginsLocalsource::pathAllowed('/srv/glpi-plugins/../../etc/passwd', self::ROOTS));
    }

    public function testTraversalThatStaysInsideIsAllowed(): void
    {
        self::assertTrue(PluginGitpluginsLocalsource::pathAllowed('/srv/glpi-plugins/sub/../assetreport', self::ROOTS));
    }

    public function testRelativePathRejected(): void
    {
        self::assertFalse(PluginGitpluginsLocalsource::pathAllowed('srv/glpi-plugins/x', self::ROOTS));
    }

    public function testEmptyRootsFailClosed(): void
    {
        self::assertFalse(PluginGitpluginsLocalsource::pathAllowed('/srv/glpi-plugins/x', []));
    }

    public function testRootSlashIsNeverHonoured(): void
    {
        // A '/' root would authorise the whole filesystem — must be ignored.
        self::assertFalse(PluginGitpluginsLocalsource::pathAllowed('/etc/passwd', ['/']));
    }

    public function testNormalisePathCollapsesAndStrips(): void
    {
        self::assertSame('/a/b', PluginGitpluginsLocalsource::normalisePath('/a//b/'));
        self::assertSame('/a/c', PluginGitpluginsLocalsource::normalisePath('/a/b/../c'));
        self::assertSame('', PluginGitpluginsLocalsource::normalisePath('relative/x'));
    }
}
