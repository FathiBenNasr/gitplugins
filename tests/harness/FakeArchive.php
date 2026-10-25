<?php
/**
 * Stand-in for GLPI's bundled wapmorgan/UnifiedArchive, scripted by the test:
 * which entries the archive lists, the sizes it declares, and the bytes each
 * entry really yields. Records every content read so a test can prove an
 * archive was refused before anything was extracted.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

namespace wapmorgan\UnifiedArchive {
    final class UnifiedArchive
    {
        /** @var string[] */
        public static array $names = [];
        /** @var array<string,int> declared uncompressed size per entry */
        public static array $declared = [];
        /** @var array<string,int> real size each entry yields */
        public static array $actual = [];
        /** @var string[] entries whose content was read */
        public static array $reads = [];
        /** Format the driver reports for the file it was handed. */
        public static string $format = 'tar';
        /** @var string[] files the library was asked to open (proves what reached it) */
        public static array $opened = [];

        public static function open(string $file): ?self
        {
            self::$opened[] = $file;

            return new self();
        }

        public function getFormat(): string
        {
            return self::$format;
        }

        /** @return string[] */
        public function getFileNames(): array
        {
            return self::$names;
        }

        public function getFileData(string $name): object
        {
            return (object) ['uncompressedSize' => self::$declared[$name] ?? 0];
        }

        /** @return resource */
        public function getFileStream(string $name)
        {
            self::$reads[] = $name;
            $h = fopen('php://temp', 'w+b');
            $left = self::$actual[$name] ?? 1;
            while ($left > 0) {
                $n = min($left, 65536);
                fwrite($h, str_repeat('A', $n));
                $left -= $n;
            }
            rewind($h);

            return $h;
        }

        public function getFileContent(string $name): string
        {
            self::$reads[] = $name;

            return str_repeat('A', self::$actual[$name] ?? 1);
        }
    }
}
