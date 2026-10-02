<?php
/**
 * Tests for the shipped translation catalogues in locales/.
 *
 * GLPI falls back to en_GB when a plugin has no catalogue for the session
 * language. Without locales/en_GB.mo, an en_GB session re-probed the missing
 * file on every plugin language load — the stat storm seen on 2026-10-02.
 * These pin that en_GB ships, that every .po has its compiled .mo, and that
 * every catalogue carries the same msgids.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CataloguesTest extends TestCase
{
    private const LOCALES_DIR = __DIR__ . '/../locales/';

    /**
     * Reads the msgids out of a compiled GNU .mo file, header excluded.
     *
     * @return list<string>
     */
    private static function moMsgids(string $file): array
    {
        $data = @file_get_contents($file);
        self::assertTrue(is_string($data) && strlen($data) >= 20, basename($file) . ' is unreadable');

        $magic = unpack('V', substr($data, 0, 4))[1];
        self::assertTrue($magic === 0x950412de || $magic === 0xde120495, basename($file) . ' is not a .mo file');
        $fmt   = $magic === 0x950412de ? 'V' : 'N';
        $count = unpack($fmt, substr($data, 8, 4))[1];
        $table = unpack($fmt, substr($data, 12, 4))[1];

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $len = unpack($fmt, substr($data, $table + $i * 8, 4))[1];
            $off = unpack($fmt, substr($data, $table + $i * 8 + 4, 4))[1];
            $id  = substr($data, $off, $len);
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        sort($ids);
        return $ids;
    }

    public function testEnGbCatalogueShips(): void
    {
        self::assertTrue(is_file(self::LOCALES_DIR . 'en_GB.po'), 'locales/en_GB.po is missing');
        self::assertTrue(is_file(self::LOCALES_DIR . 'en_GB.mo'), 'locales/en_GB.mo is missing');
    }

    public function testEveryPoHasItsMo(): void
    {
        $pos = glob(self::LOCALES_DIR . '*.po') ?: [];
        self::assertTrue($pos !== [], 'no .po catalogue found');
        foreach ($pos as $po) {
            self::assertTrue(is_file(substr($po, 0, -3) . '.mo'), basename($po) . ' was never compiled');
        }
    }

    public function testCataloguesShareTheSameMsgids(): void
    {
        $reference = self::moMsgids(self::LOCALES_DIR . 'en_US.mo');
        self::assertTrue($reference !== [], 'en_US.mo carries no msgid');
        foreach (glob(self::LOCALES_DIR . '*.mo') ?: [] as $mo) {
            self::assertSame($reference, self::moMsgids($mo), basename($mo) . ' differs from en_US.mo');
        }
    }

    public function testEnGbHeaderDeclaresItsLanguage(): void
    {
        $po = (string) @file_get_contents(self::LOCALES_DIR . 'en_GB.po');
        self::assertStringContainsString('"Language: en_GB\n"', $po);
    }
}
