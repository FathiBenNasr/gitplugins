<?php
/**
 * Pure tests for multi-target deploy (Phase 3, pull model): the SHA-pinned
 * manifest build, canonicalisation, HMAC sign/verify, request signing string,
 * and the freshness/replay window. exportForTarget/authenticate are integration.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/deploy.class.php';

final class DeployTest extends TestCase
{
    private const SOURCES = [
        ['id' => 1, 'url' => 'https://github.com/foo/a', 'provider' => 'github', 'name' => 'A', 'is_active' => 1],
        ['id' => 2, 'url' => 'https://github.com/foo/b', 'provider' => 'github', 'name' => 'B', 'is_active' => 0],
        ['id' => 3, 'url' => 'https://github.com/foo/c', 'provider' => 'github', 'name' => 'C', 'is_active' => 1],
    ];

    public function testBuildManifestPinsShaThenVersion(): void
    {
        $installs = [
            ['plugin_key' => 'a', 'installed_version' => '1.0.0', 'installed_sha' => 'deadbeef', 'plugin_gitplugins_sources_id' => 1],
            ['plugin_key' => 'c', 'installed_version' => '2.0.0', 'installed_sha' => '', 'plugin_gitplugins_sources_id' => 3],
        ];
        $m = PluginGitpluginsDeploy::buildManifest($installs, self::SOURCES);
        self::assertSame(2, count($m));
        // Ordered by plugin_key: a then c.
        self::assertSame('a', $m[0]['plugin_key']);
        self::assertSame('pin_sha', $m[0]['ref_policy']);
        self::assertSame('deadbeef', $m[0]['ref']);
        self::assertSame('pin_tag', $m[1]['ref_policy']);
        self::assertSame('2.0.0', $m[1]['ref']);
    }

    public function testBuildManifestSkipsInactiveSourceAndUninstalled(): void
    {
        $installs = [
            ['plugin_key' => 'b', 'installed_version' => '1.0.0', 'installed_sha' => 'x', 'plugin_gitplugins_sources_id' => 2], // source inactive
            ['plugin_key' => 'a', 'installed_version' => '', 'installed_sha' => '', 'plugin_gitplugins_sources_id' => 1],       // not installed
            ['plugin_key' => 'BAD KEY', 'installed_version' => '1.0', 'installed_sha' => '', 'plugin_gitplugins_sources_id' => 1],
        ];
        self::assertSame([], PluginGitpluginsDeploy::buildManifest($installs, self::SOURCES));
    }

    public function testCanonicaliseIsDeterministic(): void
    {
        $m = [
            ['plugin_key' => 'a', 'name' => 'A', 'provider' => 'github', 'ref' => 'x', 'ref_policy' => 'pin_sha', 'url' => 'https://h/a'],
        ];
        $b1 = PluginGitpluginsDeploy::canonicalise($m, 1000);
        $b2 = PluginGitpluginsDeploy::canonicalise($m, 1000);
        self::assertSame($b1, $b2);
        self::assertStringContainsString('"generated_at":1000', $b1);
        self::assertStringContainsString('"plugin_key":"a"', $b1);
    }

    public function testSignAndVerifyRoundTrip(): void
    {
        $body = 'hello';
        $sig  = PluginGitpluginsDeploy::sign($body, 's3cr3t');
        self::assertTrue($sig !== '');
        self::assertTrue(PluginGitpluginsDeploy::verify($body, 's3cr3t', $sig));
        // Uppercase hex still verifies (verify lower-cases).
        self::assertTrue(PluginGitpluginsDeploy::verify($body, 's3cr3t', strtoupper($sig)));
    }

    public function testVerifyFailsClosed(): void
    {
        $sig = PluginGitpluginsDeploy::sign('hello', 's3cr3t');
        self::assertFalse(PluginGitpluginsDeploy::verify('hello', 'wrong', $sig));
        self::assertFalse(PluginGitpluginsDeploy::verify('tampered', 's3cr3t', $sig));
        self::assertFalse(PluginGitpluginsDeploy::verify('hello', '', $sig));
        self::assertFalse(PluginGitpluginsDeploy::verify('hello', 's3cr3t', ''));
        self::assertSame('', PluginGitpluginsDeploy::sign('hello', ''));
    }

    public function testRequestStringToSignBindsMethodPathTimestampTarget(): void
    {
        $s = PluginGitpluginsDeploy::requestStringToSign('get', '/ajax/deploy.php', '1000', 'prod');
        self::assertSame("GET\n/ajax/deploy.php\n1000\nprod", $s);
    }

    public function testFreshnessWindow(): void
    {
        self::assertTrue(PluginGitpluginsDeploy::isFresh(1000, 1000));
        self::assertTrue(PluginGitpluginsDeploy::isFresh(1000, 1000 + 299));
        self::assertTrue(PluginGitpluginsDeploy::isFresh(1000, 1000 - 299));
        self::assertFalse(PluginGitpluginsDeploy::isFresh(1000, 1000 + 301));
        self::assertFalse(PluginGitpluginsDeploy::isFresh(0, 1000));
        self::assertFalse(PluginGitpluginsDeploy::isFresh(-5, 1000));
    }

    public function testEndToEndSignedRequestFlow(): void
    {
        // A target signs a pull request; the origin (with the same secret) accepts.
        $secret = 'shared-key';
        $toSign = PluginGitpluginsDeploy::requestStringToSign('GET', 'gitplugins/ajax/deploy.php', '2000', 'prod');
        $sig    = PluginGitpluginsDeploy::sign($toSign, $secret);
        self::assertTrue(PluginGitpluginsDeploy::verify($toSign, $secret, $sig));
        // A replayed-but-stale timestamp is rejected by the freshness gate.
        self::assertFalse(PluginGitpluginsDeploy::isFresh(2000, 2000 + 3600));
    }
}
