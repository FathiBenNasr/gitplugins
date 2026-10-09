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

    /**
     * L-9: run the real extractTo() on a scripted archive; true when it was
     * refused, with the number of entry contents read and whether any staging
     * dir was left behind.
     *
     * @return array{refused:bool, reads:int, leftovers:int}
     */
    private static function extract(array $names, array $declared, array $actual, ?int $maxBytes = null): array
    {
        require_once __DIR__ . '/harness/FakeArchive.php';
        \wapmorgan\UnifiedArchive\UnifiedArchive::$names    = $names;
        \wapmorgan\UnifiedArchive\UnifiedArchive::$declared = $declared;
        \wapmorgan\UnifiedArchive\UnifiedArchive::$actual   = $actual;
        \wapmorgan\UnifiedArchive\UnifiedArchive::$reads    = [];
        $before  = glob(sys_get_temp_dir() . '/gitplugins_x_*') ?: [];
        $refused = false;
        $staged  = null;
        try {
            $staged = $maxBytes === null
                ? PluginGitpluginsExtractor::extractTo('/nonexistent.tar.gz', 'foo')
                : PluginGitpluginsExtractor::extractTo('/nonexistent.tar.gz', 'foo', PluginGitpluginsExtractor::MAX_ENTRIES, $maxBytes);
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage() === 'extract_failed';
        }
        if (is_string($staged)) {
            PluginGitpluginsExtractor::rrmdir(dirname($staged));
        }
        $after = glob(sys_get_temp_dir() . '/gitplugins_x_*') ?: [];

        return [
            'refused'   => $refused,
            'reads'     => count(\wapmorgan\UnifiedArchive\UnifiedArchive::$reads),
            'leftovers' => count(array_diff($after, $before)),
        ];
    }

    public function testBudgetRejectsBombBeforeWriting(): void
    {
        // Too many entries.
        $many = ['r/setup.php'];
        for ($i = 0; $i <= PluginGitpluginsExtractor::MAX_ENTRIES; $i++) {
            $many[] = "r/f{$i}.php";
        }
        $r = self::extract($many, [], []);
        self::assertTrue($r['refused']);
        self::assertSame(0, $r['reads'], 'refused before a single entry is read');
        self::assertSame(0, $r['leftovers']);

        // Few entries, but the declared expansion is a bomb.
        $r = self::extract(['r/setup.php', 'r/big.bin'], ['r/big.bin' => PluginGitpluginsExtractor::MAX_TOTAL_BYTES + 1], []);
        self::assertTrue($r['refused']);
        self::assertSame(0, $r['reads']);
        self::assertSame(0, $r['leftovers']);
    }

    public function testBudgetHoldsWhenDeclaredSizesLie(): void
    {
        // Declares nothing, but the entries really expand past the budget
        // (a 4 KiB budget here, so the test writes almost nothing).
        $r = self::extract(['r/setup.php', 'r/a.bin', 'r/b.bin'], [], ['r/a.bin' => 3000, 'r/b.bin' => 3000], 4096);
        self::assertTrue($r['refused']);
        self::assertSame(0, $r['leftovers'], 'the partial extraction is cleaned up');
    }

    public function testNormalArchiveStillExtracts(): void
    {
        $r = self::extract(['r/', 'r/setup.php', 'r/inc/a.php'], ['r/setup.php' => 10, 'r/inc/a.php' => 10], []);
        self::assertFalse($r['refused']);
        self::assertSame(2, $r['reads']);
        self::assertSame(0, $r['leftovers']);
    }

    public function testWithinBudget(): void
    {
        self::assertTrue(PluginGitpluginsExtractor::withinBudget(10, 1000));
        self::assertFalse(PluginGitpluginsExtractor::withinBudget(PluginGitpluginsExtractor::MAX_ENTRIES + 1, 0));
        self::assertFalse(PluginGitpluginsExtractor::withinBudget(1, PluginGitpluginsExtractor::MAX_TOTAL_BYTES + 1));
        self::assertFalse(PluginGitpluginsExtractor::withinBudget(-1, 0));
    }

    public function testLayoutHasSetup(): void
    {
        self::assertTrue(PluginGitpluginsExtractor::layoutHasSetup(['r/setup.php', 'r/inc/a.php'], 'r'));
        self::assertFalse(PluginGitpluginsExtractor::layoutHasSetup(['r/inc/a.php'], 'r'));
    }
}
