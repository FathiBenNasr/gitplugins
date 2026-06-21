<?php
/**
 * Pure tests for URL normalisation + provider detection. PluginGitpluginsSource
 * extends CommonDBTM, so when run outside a full GLPI bootstrap we define a
 * minimal stub (lesson #12) — the methods under test touch no DB.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

// A real PHPUnit-under-GLPI run already has the genuine CommonDBTM; this guard
// lets the file load standalone for the pure-logic checks.
if (!class_exists('CommonDBTM')) {
    class CommonDBTM {}
}

require_once __DIR__ . '/../inc/source.class.php';

final class SourceTest extends TestCase
{
    public function testNormaliseUrlStripsWhitespaceAndTrailingSlash(): void
    {
        self::assertSame('https://github.com/foo/bar', PluginGitpluginsSource::normaliseUrl("  https://github.com/foo/bar/ \n"));
        self::assertSame('https://x.tn/a/b', PluginGitpluginsSource::normaliseUrl("https://x.tn/a/b\r\n"));
    }

    public function testDeriveProvider(): void
    {
        self::assertSame('github', PluginGitpluginsSource::deriveProvider('https://github.com/foo/bar'));
        self::assertSame('gitlab', PluginGitpluginsSource::deriveProvider('https://gitlab.com/foo/bar'));
        self::assertSame('forgejo', PluginGitpluginsSource::deriveProvider('https://git.convergent.tn/foo/bar'));
        self::assertSame('gitea', PluginGitpluginsSource::deriveProvider('https://codeberg.org/foo/bar'));
        self::assertSame('unknown', PluginGitpluginsSource::deriveProvider('https://example.com/foo/bar'));
    }

    public function testHostOf(): void
    {
        self::assertSame('github.com', PluginGitpluginsSource::hostOf('https://github.com/foo/bar'));
        self::assertSame('', PluginGitpluginsSource::hostOf('not-a-url'));
    }
}
