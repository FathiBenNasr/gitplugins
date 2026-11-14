<?php
/**
 * The optional "Nos Produits et Services" menu of the update digest: OFF by
 * default, present when enabled, identical to brand.json, never a foreign link.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/version.class.php';
require_once __DIR__ . '/../inc/services.class.php';
require_once __DIR__ . '/../inc/updatecheck.class.php';
require_once __DIR__ . '/../inc/catalog.class.php';
require_once __DIR__ . '/../inc/config.class.php';

final class ServicesTest extends TestCase
{
    private const ROWS = [['plugin_key' => 'comm', 'installed_version' => '1.0.0', 'available_version' => '1.1.0', 'repo' => '']];

    /** A public install never sends the menu: it is off unless enabled. */
    public function testDigestHasNoMenuByDefault(): void
    {
        [, $body] = PluginGitpluginsUpdatecheck::buildDigest(self::ROWS);
        self::assertStringNotContainsString('Nos Produits et Services', $body);
        self::assertStringNotContainsString('convergent.tn', $body);
    }

    public function testDigestEndsWithTheMenuWhenEnabled(): void
    {
        [, $body] = PluginGitpluginsUpdatecheck::buildDigest(self::ROWS, true);
        self::assertStringContainsString("\n\nNos Produits et Services\n", $body);
        foreach (PluginGitpluginsServices::SERVICES as $svc) {
            self::assertStringContainsString("\n{$svc['icon']} {$svc['label']} : {$svc['url']}", $body);
        }
        self::assertStringContainsString('comm: 1.0.0 → 1.1.0', $body);
    }

    /** A config row without the column (not yet upgraded) or at 0 reads as OFF. */
    public function testConfigFlagDefaultsToOff(): void
    {
        self::assertFalse(PluginGitpluginsConfig::servicesMenuFromRow([]));
        self::assertFalse(PluginGitpluginsConfig::servicesMenuFromRow(['notify_services_menu' => 0]));
        self::assertTrue(PluginGitpluginsConfig::servicesMenuFromRow(['notify_services_menu' => 1]));
    }

    /** ☁, 🛡 and 🛠 keep U+FE0F, without which Outlook draws them black and white. */
    public function testIconsKeepTheColourSelector(): void
    {
        $icons = array_column(PluginGitpluginsServices::SERVICES, 'icon');
        foreach (["\u{2601}", "\u{1F6E1}", "\u{1F6E0}"] as $base) {
            self::assertContains($base . "\u{FE0F}", $icons);
        }
    }

    public function testMenuIsIdenticalToBrandJsonIfPresent(): void
    {
        $file = __DIR__ . '/../../GLPI-notification-templates/brand.json';
        if (!is_file($file)) {
            // Public repository: brand.json lives in a private sibling repo.
            self::assertTrue(true);
            return;
        }
        $brand = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame($brand['services_title'], PluginGitpluginsServices::TITLE);
        self::assertSame(
            array_map(static fn (array $s): array => [$s['icon'] ?? '', $s['label'], $s['url']], $brand['services']),
            array_map(static fn (array $s): array => [$s['icon'], $s['label'], $s['url']], PluginGitpluginsServices::SERVICES)
        );
    }

    /** One unsafe entry and the whole menu is left out: never a link to a third party. */
    public function testUnsafeEntriesDropTheMenu(): void
    {
        $ok = ['icon' => '', 'label' => 'X', 'url' => 'https://www.convergent.tn/x.html'];
        self::assertTrue(PluginGitpluginsServices::text([$ok]) !== '');
        foreach ([
            ['url' => 'http://www.convergent.tn/edr.html'],
            ['url' => 'https://exemple.com/'],
            ['url' => 'https://www.convergent.tn.evil.example/'],
            ['url' => 'javascript:alert(1)'],
            ['url' => 'https://www.convergent.tn/x" onclick="alert(1)'],
            ['icon' => '<b>'],
            ['label' => "a\nb"],
            ['label' => ''],
        ] as $patch) {
            self::assertSame('', PluginGitpluginsServices::text([$ok, array_merge($ok, $patch)]), json_encode($patch));
        }
    }
}
