<?php
/**
 * Configuration persistence — the catalog URL list is stored whole (the column
 * is TEXT); a 255-character cut used to store half a URL when several catalogs
 * were configured.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/catalog.class.php';
require_once __DIR__ . '/../inc/config.class.php';

final class ConfigTest extends TestCase
{
    public function testCatalogUrlListNotTruncatedMidUrl(): void
    {
        $urls = [];
        for ($i = 0; $i < 6; $i++) {
            $urls[] = 'https://catalog' . $i . '.example.org/' . str_repeat('path/', 10) . 'catalog.json';
        }
        $stored = PluginGitpluginsConfig::catalogUrlColumn(implode("\n", $urls));
        self::assertTrue(strlen((string) $stored) > 255, 'the list is longer than the old cut');
        self::assertSame($urls, explode("\n", (string) $stored), 'every URL survives intact');
    }

    public function testCatalogUrlColumnDropsInvalidAndEmpty(): void
    {
        self::assertNull(PluginGitpluginsConfig::catalogUrlColumn(''));
        self::assertNull(PluginGitpluginsConfig::catalogUrlColumn("http://plain.example.org/c.json\nnot a url"));
        self::assertSame('https://a.example.org/c.json', PluginGitpluginsConfig::catalogUrlColumn("http://x/y\nhttps://a.example.org/c.json"));
    }
}
