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

        public static function open(string $file): ?self
        {
            return new self();
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

        public function getFileContent(string $name): string
        {
            self::$reads[] = $name;

            return str_repeat('A', self::$actual[$name] ?? 1);
        }
    }
}
