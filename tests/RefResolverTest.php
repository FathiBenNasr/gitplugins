<?php
/**
 * Pure tests for ref validation + archive/API URL building. No GLPI bootstrap.
 *
 *   cd plugins/gitplugins && phpunit tests/RefResolverTest.php
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/refresolver.class.php';

final class RefResolverTest extends TestCase
{
    public function testValidRefAcceptsBranchesTagsShas(): void
    {
        self::assertTrue(PluginGitpluginsRefResolver::isValidRef('main'));
        self::assertTrue(PluginGitpluginsRefResolver::isValidRef('v1.2.3'));
        self::assertTrue(PluginGitpluginsRefResolver::isValidRef('release/1.x'));
        self::assertTrue(PluginGitpluginsRefResolver::isValidRef('a1b2c3d'));
    }

    public function testValidRefRejectsTraversalAndInjection(): void
    {
        self::assertFalse(PluginGitpluginsRefResolver::isValidRef('../etc'));
        self::assertFalse(PluginGitpluginsRefResolver::isValidRef('a..b'));
        self::assertFalse(PluginGitpluginsRefResolver::isValidRef('/abs'));
        self::assertFalse(PluginGitpluginsRefResolver::isValidRef('-rf'));
        self::assertFalse(PluginGitpluginsRefResolver::isValidRef("a\r\nb"));
        self::assertFalse(PluginGitpluginsRefResolver::isValidRef('a b'));
        self::assertFalse(PluginGitpluginsRefResolver::isValidRef(''));
    }

    public function testRepoPathStripsGitSuffix(): void
    {
        self::assertSame('foo/bar', PluginGitpluginsRefResolver::repoPath('https://github.com/foo/bar.git'));
        self::assertSame('foo/bar', PluginGitpluginsRefResolver::repoPath('https://github.com/foo/bar/'));
        self::assertNull(PluginGitpluginsRefResolver::repoPath('https://github.com/'));
        self::assertNull(PluginGitpluginsRefResolver::repoPath('https://github.com/a/../b'));
    }

    public function testArchiveUrlPerProvider(): void
    {
        self::assertSame(
            'https://codeload.github.com/foo/bar/tar.gz/v1.0.0',
            PluginGitpluginsRefResolver::archiveUrl('github', 'https://github.com/foo/bar', 'v1.0.0')
        );
        self::assertSame(
            'https://gitlab.com/foo/bar/-/archive/main/bar-main.tar.gz',
            PluginGitpluginsRefResolver::archiveUrl('gitlab', 'https://gitlab.com/foo/bar', 'main')
        );
        self::assertSame(
            'https://git.convergent.tn/fbennasr/comm/archive/v0.1.0.tar.gz',
            PluginGitpluginsRefResolver::archiveUrl('forgejo', 'https://git.convergent.tn/fbennasr/comm', 'v0.1.0')
        );
    }

    public function testArchiveUrlRejectsBadInput(): void
    {
        self::assertNull(PluginGitpluginsRefResolver::archiveUrl('github', 'http://github.com/foo/bar', 'main')); // not https
        self::assertNull(PluginGitpluginsRefResolver::archiveUrl('github', 'https://github.com/foo/bar', '../x'));
        self::assertNull(PluginGitpluginsRefResolver::archiveUrl('mystery', 'https://x.tn/a/b', 'main'));
    }

    public function testTagsApiUrl(): void
    {
        self::assertSame('https://api.github.com/repos/foo/bar/tags', PluginGitpluginsRefResolver::tagsApiUrl('github', 'https://github.com/foo/bar'));
        self::assertSame('https://gitlab.com/api/v4/projects/foo%2Fbar/repository/tags', PluginGitpluginsRefResolver::tagsApiUrl('gitlab', 'https://gitlab.com/foo/bar'));
    }
}
