<?php
/**
 * Pure tests for changelog surfacing (Phase 8): parsing a Keep-a-Changelog
 * markdown into version sections and slicing the sections between an installed
 * and an available version. Also covers the RefResolver::rawFileUrls builder.
 * fetchFor() is integration (network/FS).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/version.class.php';
require_once __DIR__ . '/../inc/refresolver.class.php';
require_once __DIR__ . '/../inc/changelog.class.php';

final class ChangelogTest extends TestCase
{
    private const CL = <<<MD
    # Changelog

    All notable changes.

    ## [1.3.0] - 2024-03-01
    ### Added
    - Feature C

    ## [1.2.0] - 2024-02-01
    ### Fixed
    - Bug B

    ## 1.1.0
    - Thing A

    ## [1.0.0] - 2024-01-01
    - Initial
    MD;

    public function testParseSectionsFindsEachVersion(): void
    {
        $secs = PluginGitpluginsChangelog::parseSections(self::CL);
        $vers = array_map(static fn ($s) => $s['version'], $secs);
        self::assertSame(['1.3.0', '1.2.0', '1.1.0', '1.0.0'], $vers);
    }

    public function testSliceBetweenInstalledAndAvailable(): void
    {
        // installed 1.1.0, available 1.3.0 → sections 1.2.0 and 1.3.0 only.
        $out = PluginGitpluginsChangelog::sliceBetween(self::CL, '1.1.0', '1.3.0');
        self::assertStringContainsString('1.3.0', $out);
        self::assertStringContainsString('1.2.0', $out);
        self::assertStringContainsString('Feature C', $out);
        self::assertStringNotContainsString('1.1.0', $out);
        self::assertStringNotContainsString('Initial', $out);
    }

    public function testSliceFreshInstallUpToAvailable(): void
    {
        // Nothing installed, available 1.2.0 → everything <= 1.2.0.
        $out = PluginGitpluginsChangelog::sliceBetween(self::CL, '', '1.2.0');
        self::assertStringContainsString('1.2.0', $out);
        self::assertStringContainsString('1.0.0', $out);
        self::assertStringNotContainsString('1.3.0', $out);
    }

    public function testSliceUnknownAvailableShowsNewerThanInstalled(): void
    {
        // Available unknown (release policy) → all sections above installed.
        $out = PluginGitpluginsChangelog::sliceBetween(self::CL, '1.2.0', '');
        self::assertStringContainsString('1.3.0', $out);
        self::assertStringNotContainsString('1.2.0', $out);
    }

    public function testSliceNothingNewIsEmpty(): void
    {
        // Installed already at/above available → empty.
        self::assertSame('', PluginGitpluginsChangelog::sliceBetween(self::CL, '1.3.0', '1.3.0'));
    }

    public function testVPrefixedHeadingsParsed(): void
    {
        $md = "## v2.0.0\n- big\n\n## v1.9.0\n- small\n";
        $out = PluginGitpluginsChangelog::sliceBetween($md, '1.9.0', '2.0.0');
        self::assertStringContainsString('big', $out);
        self::assertStringNotContainsString('small', $out);
    }

    public function testNonVersionHeadingsIgnored(): void
    {
        $md = "## Unreleased\n- wip\n\n## [1.0.0]\n- done\n";
        $secs = PluginGitpluginsChangelog::parseSections($md);
        self::assertSame(1, count($secs));
        self::assertSame('1.0.0', $secs[0]['version']);
    }

    public function testEmptyOrHeadinglessInputIsEmpty(): void
    {
        self::assertSame('', PluginGitpluginsChangelog::sliceBetween('', '1.0.0', '2.0.0'));
        self::assertSame('', PluginGitpluginsChangelog::sliceBetween("just prose\nno headings\n", '', '2.0.0'));
    }

    public function testRawFileUrlsGithub(): void
    {
        $urls = PluginGitpluginsRefResolver::rawFileUrls('github', 'https://github.com/foo/bar', 'v1.2.0', 'CHANGELOG.md');
        self::assertContains('https://raw.githubusercontent.com/foo/bar/v1.2.0/CHANGELOG.md', $urls);
    }

    public function testRawFileUrlsForgejoTriesBothShapes(): void
    {
        $urls = PluginGitpluginsRefResolver::rawFileUrls('forgejo', 'https://git.convergent.tn/fbennasr/x', 'main', 'CHANGELOG.md');
        self::assertContains('https://git.convergent.tn/fbennasr/x/raw/branch/main/CHANGELOG.md', $urls);
        self::assertContains('https://git.convergent.tn/fbennasr/x/raw/main/CHANGELOG.md', $urls);
    }

    public function testRawFileUrlsRejectsUnsafeFilename(): void
    {
        self::assertSame([], PluginGitpluginsRefResolver::rawFileUrls('github', 'https://github.com/foo/bar', 'main', '../secret'));
        self::assertSame([], PluginGitpluginsRefResolver::rawFileUrls('github', 'https://github.com/foo/bar', 'main', 'a/b'));
    }
}
