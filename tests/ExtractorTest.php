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
require_once __DIR__ . '/../inc/backup.class.php';

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
    private static function extract(array $names, array $declared, array $actual, ?int $maxBytes = null, ?string $file = null): array
    {
        require_once __DIR__ . '/harness/FakeArchive.php';
        \wapmorgan\UnifiedArchive\UnifiedArchive::$names    = $names;
        \wapmorgan\UnifiedArchive\UnifiedArchive::$declared = $declared;
        \wapmorgan\UnifiedArchive\UnifiedArchive::$actual   = $actual;
        \wapmorgan\UnifiedArchive\UnifiedArchive::$reads    = [];
        \wapmorgan\UnifiedArchive\UnifiedArchive::$opened   = [];
        \wapmorgan\UnifiedArchive\UnifiedArchive::$format   = 'tar';
        $own = $file === null;
        $file ??= self::tarGz();
        $before  = glob(sys_get_temp_dir() . '/gitplugins_x_*') ?: [];
        $refused = false;
        $staged  = null;
        try {
            $staged = $maxBytes === null
                ? PluginGitpluginsExtractor::extractTo($file, 'foo')
                : PluginGitpluginsExtractor::extractTo($file, 'foo', PluginGitpluginsExtractor::MAX_ENTRIES, $maxBytes);
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage() === 'extract_failed';
        }
        if (is_string($staged)) {
            PluginGitpluginsExtractor::rrmdir(dirname($staged));
        }
        $after = glob(sys_get_temp_dir() . '/gitplugins_x_*') ?: [];
        if ($own) {
            @unlink($file);
        }

        return [
            'refused'   => $refused,
            'reads'     => count(\wapmorgan\UnifiedArchive\UnifiedArchive::$reads),
            'leftovers' => count(array_diff($after, $before)),
            'opened'    => count(\wapmorgan\UnifiedArchive\UnifiedArchive::$opened),
        ];
    }

    /** A 512-byte tar header block (POSIX ustar magic) followed by $padding zero bytes. */
    private static function tarBlock(int $padding = 1024): string
    {
        return str_pad('r/setup.php', 257, "\0") . 'ustar' . str_repeat("\0", 250) . str_repeat("\0", $padding);
    }

    /** Write a real gzip file holding $tar and return its path (a temp file). */
    private static function gz(string $tar): string
    {
        $f = tempnam(sys_get_temp_dir(), 'gp_x_t_');
        file_put_contents($f, gzencode($tar, 9));

        return $f;
    }

    private static function tarGz(): string
    {
        return self::gz(self::tarBlock());
    }

    /** A gzip of $bytes zero bytes, produced in chunks (never held whole in memory). */
    private static function gzBomb(int $bytes): string
    {
        $f   = tempnam(sys_get_temp_dir(), 'gp_x_b_');
        $ctx = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]);
        $h   = fopen($f, 'wb');
        fwrite($h, deflate_add($ctx, self::tarBlock(0), ZLIB_NO_FLUSH));
        $chunk = str_repeat("\0", 1048576);
        for ($left = $bytes; $left > 0; $left -= 1048576) {
            fwrite($h, deflate_add($ctx, $chunk, ZLIB_NO_FLUSH));
        }
        fwrite($h, deflate_add($ctx, '', ZLIB_FINISH));
        fclose($h);

        return $f;
    }

    /** C10: a tar.gz that inflates past the budget is refused while streaming, before the library opens it. */
    public function testGzipBombRefusedBeforeTheLibraryOpensIt(): void
    {
        // 300 MiB of zeros in ~0.3 MB of gzip, default budget (128 MiB).
        $f = self::gzBomb(300 * 1048576);
        self::assertTrue(filesize($f) < 2 * 1048576, 'the fixture is a small file');
        $mem = memory_get_peak_usage(true);
        $r   = self::extract(['r/setup.php'], [], [], null, $f);
        @unlink($f);
        self::assertTrue($r['refused']);
        self::assertSame(0, $r['opened'], 'the library never saw the bomb');
        self::assertSame(0, $r['leftovers'], 'the partial inflate is removed');
        self::assertTrue(memory_get_peak_usage(true) - $mem < 64 * 1048576, 'inflated through a bounded stream, not in memory');
    }

    /** C10: only zip / tar / tar.gz reach the library. */
    public function testOnlyExpectedFormatsAreOpened(): void
    {
        $dir = sys_get_temp_dir();
        $samples = [
            'rar'   => "Rar!\x1a\x07\x00" . str_repeat("\0", 600),
            '7z'    => "7z\xbc\xaf\x27\x1c" . str_repeat("\0", 600),
            'iso'   => str_repeat("\0", 32769) . 'CD001',
            'bzip2' => 'BZh9' . str_repeat("\0", 600),
            'xz'    => "\xfd7zXZ\x00" . str_repeat("\0", 600),
            'junk'  => str_repeat('x', 900),
            'empty' => '',
        ];
        foreach ($samples as $label => $bytes) {
            $f = tempnam($dir, 'gp_x_f_');
            file_put_contents($f, $bytes);
            $r = self::extract(['r/setup.php'], [], [], null, $f);
            @unlink($f);
            self::assertTrue($r['refused'], "{$label} must be refused");
            self::assertSame(0, $r['opened'], "{$label} must never reach the archive library");
        }
        // A gzip that is not a tar is refused as well.
        $f = self::gz(str_repeat('not a tar ', 200));
        $r = self::extract(['r/setup.php'], [], [], null, $f);
        @unlink($f);
        self::assertTrue($r['refused']);
        self::assertSame(0, $r['opened']);
    }

    /** C10: a driver that reports another format than the one sniffed is refused. */
    public function testFormatReportedByDriverMustMatch(): void
    {
        \wapmorgan\UnifiedArchive\UnifiedArchive::$format = 'tar';
        require_once __DIR__ . '/harness/FakeArchive.php';
        $f = self::tarGz();
        // The helper resets the format; set the mismatch through a one-off open.
        \wapmorgan\UnifiedArchive\UnifiedArchive::$names = ['r/setup.php'];
        \wapmorgan\UnifiedArchive\UnifiedArchive::$format = '7z';
        $refused = false;
        try {
            PluginGitpluginsExtractor::extractTo($f, 'foo');
        } catch (\RuntimeException $e) {
            $refused = true;
        }
        @unlink($f);
        \wapmorgan\UnifiedArchive\UnifiedArchive::$format = 'tar';
        self::assertTrue($refused);
    }

    /** C10: one entry over the per-entry cap is refused although the total budget allows it. */
    public function testSingleHugeEntryRefused(): void
    {
        $big = PluginGitpluginsExtractor::MAX_ENTRY_BYTES + 1;
        $r   = self::extract(['r/setup.php', 'r/big.bin'], [], ['r/big.bin' => $big], PluginGitpluginsExtractor::MAX_TOTAL_BYTES);
        self::assertTrue($r['refused']);
        self::assertSame(0, $r['leftovers']);
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

    /** @return array{0:string,1:string,2:string} work dir, plugins dir, external tree */
    private static function linkedPlugin(): array
    {
        $w = sys_get_temp_dir() . '/gp_link_' . bin2hex(random_bytes(6));
        mkdir($w . '/plugins', 0o750, true);
        mkdir($w . '/ext/p/inc', 0o750, true);
        file_put_contents($w . '/ext/p/setup.php', "<?php // working tree\n");
        file_put_contents($w . '/ext/p/inc/a.php', "<?php\n");
        symlink($w . '/ext/p', $w . '/plugins/p');

        return [$w, $w . '/plugins', $w . '/ext/p'];
    }

    /** C3: replacing plugins/<key> when it is a link must not touch the link target. */
    public function testPlaceAtomicallyRefusesALinkedPluginDir(): void
    {
        [$w, $plugins, $ext] = self::linkedPlugin();
        mkdir($w . '/staged/p', 0o750, true);
        file_put_contents($w . '/staged/p/setup.php', "<?php // new\n");
        $refused = false;
        try {
            PluginGitpluginsExtractor::placeAtomically($w . '/staged/p', $plugins, 'p', '1.0', []);
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage() === 'place_failed';
        }
        self::assertTrue($refused);
        self::assertTrue(is_file($ext . '/setup.php'), 'the tree behind the link is intact');
        self::assertTrue(is_file($ext . '/inc/a.php'));
        self::assertTrue(is_link($plugins . '/p'), 'the link itself is untouched');
        self::assertSame([], glob($plugins . '/.gitplugins_swap_*') ?: []);
        self::assertTrue(is_file($w . '/staged/p/setup.php'), 'the staged tree stays for the caller to clean');
        PluginGitpluginsExtractor::rrmdir($w);
    }

    /** C3: a plugins/ directory that is itself a link is refused too. */
    public function testPlaceAtomicallyRefusesALinkedPluginsBase(): void
    {
        $w = sys_get_temp_dir() . '/gp_link_' . bin2hex(random_bytes(6));
        mkdir($w . '/real', 0o750, true);
        symlink($w . '/real', $w . '/plugins');
        mkdir($w . '/staged/p', 0o750, true);
        file_put_contents($w . '/staged/p/setup.php', "<?php\n");
        $refused = false;
        try {
            PluginGitpluginsExtractor::placeAtomically($w . '/staged/p', $w . '/plugins', 'p', '', []);
        } catch (\RuntimeException $e) {
            $refused = true;
        }
        self::assertTrue($refused);
        self::assertSame([], glob($w . '/real/*') ?: [], 'nothing was written through the link');
        PluginGitpluginsExtractor::rrmdir($w);
    }

    /** C3: rrmdir on a link removes the link, never the tree behind it. */
    public function testRrmdirNeverFollowsALink(): void
    {
        [$w, $plugins, $ext] = self::linkedPlugin();
        PluginGitpluginsExtractor::rrmdir($plugins . '/p');
        self::assertFalse(is_link($plugins . '/p'));
        self::assertTrue(is_file($ext . '/setup.php'), 'the target tree survives');
        self::assertTrue(is_file($ext . '/inc/a.php'));
        PluginGitpluginsExtractor::rrmdir($w);
    }

    /** C3: restoring a rollback backup onto a linked plugin dir is refused, target untouched. */
    public function testRestoreRefusesALinkedPluginDir(): void
    {
        [$w, $plugins, $ext] = self::linkedPlugin();
        $zip = new \ZipArchive();
        $zipPath = $w . '/b.zip';
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('p/setup.php', "<?php // old\n");
        $zip->close();
        self::assertFalse(PluginGitpluginsBackup::restore($zipPath, $plugins, 'p'));
        self::assertSame("<?php // working tree\n", file_get_contents($ext . '/setup.php'));
        self::assertTrue(is_file($ext . '/inc/a.php'));
        PluginGitpluginsExtractor::rrmdir($w);
    }

    /** Control: a real directory is still replaced. */
    public function testPlaceAtomicallyStillReplacesARealDir(): void
    {
        $w = sys_get_temp_dir() . '/gp_link_' . bin2hex(random_bytes(6));
        mkdir($w . '/plugins/p', 0o750, true);
        file_put_contents($w . '/plugins/p/setup.php', "<?php // old\n");
        mkdir($w . '/staged/p', 0o750, true);
        file_put_contents($w . '/staged/p/setup.php', "<?php // new\n");
        PluginGitpluginsExtractor::placeAtomically($w . '/staged/p', $w . '/plugins', 'p', '', []);
        self::assertSame("<?php // new\n", file_get_contents($w . '/plugins/p/setup.php'));
        PluginGitpluginsExtractor::rrmdir($w);
    }
}
