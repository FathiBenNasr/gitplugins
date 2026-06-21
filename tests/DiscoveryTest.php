<?php
/**
 * Pure tests for the discovery state machine + marketplace-dir detection. No GLPI
 * bootstrap / DB / FS — decideState() and isMarketplaceDir() are side-effect-free
 * (the I/O that resolves the real plugin directory lives in isMarketplacePlugin(),
 * which is exercised only on the live box).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/discovery.class.php';

final class DiscoveryTest extends TestCase
{
    public function testDecideStateMarketplaceWinsOverEverything(): void
    {
        $manifest = ['repo' => 'https://github.com/foo/bar'];
        // Marketplace beats a managed source and a declaration alike (read-only).
        self::assertSame('marketplace', PluginGitpluginsDiscovery::decideState($manifest, true, true));
        self::assertSame('marketplace', PluginGitpluginsDiscovery::decideState($manifest, false, true));
        self::assertSame('marketplace', PluginGitpluginsDiscovery::decideState(null, false, true));
    }

    public function testDecideStatePluginsDirKeepsExistingBehaviour(): void
    {
        $manifest = ['repo' => 'https://github.com/foo/bar'];
        // Not marketplace → the original managed/declared/none ordering holds.
        self::assertSame('managed', PluginGitpluginsDiscovery::decideState($manifest, true, false));
        self::assertSame('declared', PluginGitpluginsDiscovery::decideState($manifest, false, false));
        self::assertSame('none', PluginGitpluginsDiscovery::decideState(null, false, false));
        self::assertSame('none', PluginGitpluginsDiscovery::decideState(['repo' => ''], false, false));
        // Default arg keeps marketplace off.
        self::assertSame('managed', PluginGitpluginsDiscovery::decideState($manifest, true));
    }

    public function testIsMarketplaceDirDetectsMarketplaceChild(): void
    {
        self::assertTrue(PluginGitpluginsDiscovery::isMarketplaceDir(
            '/var/www/glpi/marketplace/news',
            '/var/www/glpi/marketplace',
            '/var/www/glpi/plugins'
        ));
        // The marketplace dir itself.
        self::assertTrue(PluginGitpluginsDiscovery::isMarketplaceDir(
            '/var/www/glpi/marketplace',
            '/var/www/glpi/marketplace',
            '/var/www/glpi/plugins'
        ));
        // Trailing slashes are normalised.
        self::assertTrue(PluginGitpluginsDiscovery::isMarketplaceDir(
            '/var/www/glpi/marketplace/news/',
            '/var/www/glpi/marketplace/',
            '/var/www/glpi/plugins/'
        ));
    }

    public function testIsMarketplaceDirRejectsPluginsDir(): void
    {
        // A manually-installed plugin under plugins/ is gitplugins-managed.
        self::assertFalse(PluginGitpluginsDiscovery::isMarketplaceDir(
            '/var/www/glpi/plugins/comm',
            '/var/www/glpi/marketplace',
            '/var/www/glpi/plugins'
        ));
        // plugins/ takes precedence even when both prefixes could match.
        self::assertFalse(PluginGitpluginsDiscovery::isMarketplaceDir(
            '/var/www/glpi/plugins/comm',
            '/var/www/glpi',
            '/var/www/glpi/plugins'
        ));
    }

    public function testIsMarketplaceDirDegradesWhenUnknown(): void
    {
        // No resolved dir, or no marketplace dir → not marketplace (safe default).
        self::assertFalse(PluginGitpluginsDiscovery::isMarketplaceDir('', '/mkt', '/plg'));
        self::assertFalse(PluginGitpluginsDiscovery::isMarketplaceDir('/some/dir', '', '/plg'));
        // A sibling that merely shares a name prefix must NOT match.
        self::assertFalse(PluginGitpluginsDiscovery::isMarketplaceDir(
            '/var/www/glpi/marketplace-backup/x',
            '/var/www/glpi/marketplace',
            '/var/www/glpi/plugins'
        ));
    }
}
