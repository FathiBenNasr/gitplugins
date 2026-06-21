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

    // ----- rawManifestUrls(): raw plugin.xml URL resolution per provider -----

    public function testRawManifestUrlsGithubWithRef(): void
    {
        // The explicit ref is tried first; the usual defaults follow as a fallback.
        $urls = PluginGitpluginsRefResolver::rawManifestUrls('github', 'https://github.com/foo/bar.git', 'v1.0.0');
        self::assertSame('https://raw.githubusercontent.com/foo/bar/v1.0.0/plugin.xml', $urls[0]);
        self::assertContains('https://raw.githubusercontent.com/foo/bar/HEAD/plugin.xml', $urls);
    }

    public function testRawManifestUrlsForgejoTriesBothShapesAndDefaultRefs(): void
    {
        // No ref → HEAD, main, master, each in /raw/branch/ then /raw/ form.
        $urls = PluginGitpluginsRefResolver::rawManifestUrls('forgejo', 'https://git.convergent.tn/a/comm', '');
        self::assertContains('https://git.convergent.tn/a/comm/raw/branch/HEAD/plugin.xml', $urls);
        self::assertContains('https://git.convergent.tn/a/comm/raw/HEAD/plugin.xml', $urls);
        self::assertContains('https://git.convergent.tn/a/comm/raw/branch/main/plugin.xml', $urls);
        self::assertContains('https://git.convergent.tn/a/comm/raw/branch/master/plugin.xml', $urls);
        // First candidate is the explicit-shape default branch.
        self::assertSame('https://git.convergent.tn/a/comm/raw/branch/HEAD/plugin.xml', $urls[0]);
    }

    public function testRawManifestUrlsGitlab(): void
    {
        $urls = PluginGitpluginsRefResolver::rawManifestUrls('gitlab', 'https://gitlab.com/foo/bar', 'main');
        self::assertSame('https://gitlab.com/foo/bar/-/raw/main/plugin.xml', $urls[0]);
        // 'main' is also a default, so it isn't duplicated; HEAD/master follow.
        self::assertContains('https://gitlab.com/foo/bar/-/raw/HEAD/plugin.xml', $urls);
    }

    public function testRawManifestUrlsRejectsBadInput(): void
    {
        self::assertSame([], PluginGitpluginsRefResolver::rawManifestUrls('github', 'http://github.com/foo/bar', 'main')); // not https
        self::assertSame([], PluginGitpluginsRefResolver::rawManifestUrls('github', 'https://github.com/', 'main'));      // no repo path
        self::assertSame([], PluginGitpluginsRefResolver::rawManifestUrls('mystery', 'https://x.tn/a/b', 'main'));        // unknown provider
    }

    public function testRawManifestUrlsInvalidRefFallsBackToDefaults(): void
    {
        // A bad explicit ref is dropped; default branches are still tried.
        $urls = PluginGitpluginsRefResolver::rawManifestUrls('github', 'https://github.com/foo/bar', '../evil');
        self::assertSame([
            'https://raw.githubusercontent.com/foo/bar/HEAD/plugin.xml',
            'https://raw.githubusercontent.com/foo/bar/main/plugin.xml',
            'https://raw.githubusercontent.com/foo/bar/master/plugin.xml',
        ], $urls);
    }

    // ----- releaseApiUrls(): built-release endpoint resolution per provider -----

    public function testReleaseApiUrlsGithubLatestAndTag(): void
    {
        self::assertSame(
            ['https://api.github.com/repos/foo/bar/releases/latest'],
            PluginGitpluginsRefResolver::releaseApiUrls('github', 'https://github.com/foo/bar', '')
        );
        self::assertSame(
            ['https://api.github.com/repos/foo/bar/releases/tags/v1.2.3'],
            PluginGitpluginsRefResolver::releaseApiUrls('github', 'https://github.com/foo/bar.git', 'v1.2.3')
        );
    }

    public function testReleaseApiUrlsForgejoLatestAndTag(): void
    {
        self::assertSame(
            ['https://git.convergent.tn/api/v1/repos/a/comm/releases/latest'],
            PluginGitpluginsRefResolver::releaseApiUrls('forgejo', 'https://git.convergent.tn/a/comm', '')
        );
        self::assertSame(
            ['https://git.convergent.tn/api/v1/repos/a/comm/releases/tags/v2.0.0'],
            PluginGitpluginsRefResolver::releaseApiUrls('gitea', 'https://git.convergent.tn/a/comm', 'v2.0.0')
        );
    }

    public function testReleaseApiUrlsGitlab(): void
    {
        self::assertSame(
            ['https://gitlab.com/api/v4/projects/foo%2Fbar/releases'],
            PluginGitpluginsRefResolver::releaseApiUrls('gitlab', 'https://gitlab.com/foo/bar', '')
        );
        self::assertSame(
            ['https://gitlab.com/api/v4/projects/foo%2Fbar/releases/v1.0.0'],
            PluginGitpluginsRefResolver::releaseApiUrls('gitlab', 'https://gitlab.com/foo/bar', 'v1.0.0')
        );
    }

    public function testReleaseApiUrlsRejectsBadInput(): void
    {
        self::assertSame([], PluginGitpluginsRefResolver::releaseApiUrls('github', 'http://github.com/foo/bar', '')); // not https
        self::assertSame([], PluginGitpluginsRefResolver::releaseApiUrls('github', 'https://github.com/', ''));       // no repo path
        self::assertSame([], PluginGitpluginsRefResolver::releaseApiUrls('mystery', 'https://x.tn/a/b', ''));         // unknown provider
        // An invalid tag is dropped → resolves to "latest" instead of injecting.
        self::assertSame(
            ['https://api.github.com/repos/foo/bar/releases/latest'],
            PluginGitpluginsRefResolver::releaseApiUrls('github', 'https://github.com/foo/bar', '../evil')
        );
    }

    // ----- pickReleaseAsset(): choose the .tgz built asset, else tarball -----

    public function testPickReleaseAssetPrefersTgzAsset(): void
    {
        $release = [
            'tag_name'    => 'v1.0.0',
            'tarball_url' => 'https://api.github.com/repos/foo/bar/tarball/v1.0.0',
            'assets'      => [
                ['name' => 'notes.txt',      'browser_download_url' => 'https://x/notes.txt'],
                ['name' => 'plugin-1.0.tgz', 'browser_download_url' => 'https://objects.githubusercontent.com/plugin-1.0.tgz'],
            ],
        ];
        self::assertSame(
            'https://objects.githubusercontent.com/plugin-1.0.tgz',
            PluginGitpluginsRefResolver::pickReleaseAsset($release)
        );
        self::assertSame('v1.0.0', PluginGitpluginsRefResolver::releaseTag($release));
    }

    public function testPickReleaseAssetAcceptsTarGzExtension(): void
    {
        $release = ['assets' => [['name' => 'built.tar.gz', 'browser_download_url' => 'https://x/built.tar.gz']]];
        self::assertSame('https://x/built.tar.gz', PluginGitpluginsRefResolver::pickReleaseAsset($release));
    }

    public function testPickReleaseAssetFallsBackToTarballUrl(): void
    {
        $release = [
            'tarball_url' => 'https://api.github.com/repos/foo/bar/tarball/v1.0.0',
            'assets'      => [['name' => 'readme.pdf', 'browser_download_url' => 'https://x/readme.pdf']],
        ];
        self::assertSame(
            'https://api.github.com/repos/foo/bar/tarball/v1.0.0',
            PluginGitpluginsRefResolver::pickReleaseAsset($release)
        );
    }

    public function testPickReleaseAssetNullWhenNothingUsable(): void
    {
        self::assertNull(PluginGitpluginsRefResolver::pickReleaseAsset([]));
        self::assertNull(PluginGitpluginsRefResolver::pickReleaseAsset(['assets' => []]));
    }
}
