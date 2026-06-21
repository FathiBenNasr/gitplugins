<?php
/**
 * Pure tests for zip-slip path sanitisation + single-root layout validation.
 * No GLPI bootstrap / FS / network. The I/O methods (extractTo/placeAtomically)
 * are live-box only and not exercised here.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/extractor.class.php';

final class ExtractorTest extends TestCase
{
    public function testRejectsTraversalAndAbsolute(): void
    {
        foreach ([
            '../../etc/cron.d/x',
            '/etc/passwd',
            'a/../../b',
            'a/../b',
            "a\0b",
            'a\\b',          // backslash
            'C:\\windows',
            '..',
            '',
        ] as $bad) {
            self::assertNull(PluginGitpluginsExtractor::sanitiseEntryPath($bad), "expected reject: {$bad}");
        }
    }

    public function testAcceptsAndNormalisesSafePaths(): void
    {
        self::assertSame('repo-1.0/setup.php', PluginGitpluginsExtractor::sanitiseEntryPath('repo-1.0/setup.php'));
        self::assertSame('repo/inc/x.php', PluginGitpluginsExtractor::sanitiseEntryPath('./repo/inc/x.php'));
        self::assertSame('repo/inc', PluginGitpluginsExtractor::sanitiseEntryPath('repo//inc/'));
    }

    public function testSingleTopDir(): void
    {
        self::assertSame('repo-1.0', PluginGitpluginsExtractor::singleTopDir([
            'repo-1.0/setup.php', 'repo-1.0/inc/a.php',
        ]));
        self::assertNull(PluginGitpluginsExtractor::singleTopDir([
            'repo-1.0/setup.php', 'other/x.php',
        ]));
        self::assertNull(PluginGitpluginsExtractor::singleTopDir([]));
    }

    public function testLayoutHasSetup(): void
    {
        self::assertTrue(PluginGitpluginsExtractor::layoutHasSetup(['r/setup.php', 'r/inc/a.php'], 'r'));
        self::assertFalse(PluginGitpluginsExtractor::layoutHasSetup(['r/inc/a.php'], 'r'));
    }
}
