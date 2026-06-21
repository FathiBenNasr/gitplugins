<?php
/**
 * Pure tests for the A10 SSRF guard — the plugin's highest-risk surface.
 * isBlockedIp is tabled exhaustively; assertSafeUrl is driven with an injected
 * resolver so no real DNS is touched. No GLPI bootstrap / network.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/fetcher.class.php';

final class SsrfGuardTest extends TestCase
{
    public function testBlockedIpsAreBlocked(): void
    {
        foreach ([
            '127.0.0.1', '127.5.5.5',          // loopback
            '10.0.0.1', '172.16.0.1', '192.168.1.1', // private
            '169.254.169.254',                 // cloud metadata
            '169.254.0.1',                     // link-local
            '0.0.0.0',
            '::1',                             // IPv6 loopback
            'fc00::1', 'fd12:3456::1',         // ULA
            'fe80::1',                         // IPv6 link-local
            '::ffff:169.254.169.254',          // IPv4-mapped metadata
            '::ffff:10.0.0.1',                 // IPv4-mapped private
            'not-an-ip',                       // invalid → fail closed
        ] as $ip) {
            self::assertTrue(PluginGitpluginsFetcher::isBlockedIp($ip), "expected blocked: {$ip}");
        }
    }

    public function testPublicIpsPass(): void
    {
        foreach (['8.8.8.8', '1.1.1.1', '140.82.121.3', '2606:4700:4700::1111'] as $ip) {
            self::assertFalse(PluginGitpluginsFetcher::isBlockedIp($ip), "expected allowed: {$ip}");
        }
    }

    public function testAssertSafeUrlHappyPath(): void
    {
        $ips = PluginGitpluginsFetcher::assertSafeUrl(
            'https://github.com/foo/bar',
            ['github.com'],
            static fn (string $h): array => ['140.82.121.3']
        );
        self::assertSame(['140.82.121.3'], $ips);
    }

    public function testRejectsNonHttps(): void
    {
        $this->expectException(\RuntimeException::class);
        PluginGitpluginsFetcher::assertSafeUrl('http://github.com/foo', ['github.com'], static fn ($h) => ['8.8.8.8']);
    }

    public function testRejectsHostNotAllowed(): void
    {
        $this->expectException(\RuntimeException::class);
        PluginGitpluginsFetcher::assertSafeUrl('https://evil.example/foo', ['github.com'], static fn ($h) => ['8.8.8.8']);
    }

    public function testRejectsResolvedPrivateIp(): void
    {
        // Allowed host, but it resolves to a private address (DNS rebinding).
        $this->expectException(\RuntimeException::class);
        PluginGitpluginsFetcher::assertSafeUrl('https://github.com/foo', ['github.com'], static fn ($h) => ['10.0.0.5']);
    }

    public function testRejectsUserinfoAndOddPort(): void
    {
        $r = static fn ($h) => ['8.8.8.8'];
        $caught = 0;
        foreach (['https://user:pass@github.com/foo', 'https://github.com:22/foo'] as $u) {
            try {
                PluginGitpluginsFetcher::assertSafeUrl($u, ['github.com'], $r);
            } catch (\RuntimeException $e) {
                $caught++;
            }
        }
        self::assertSame(2, $caught);
    }
}
