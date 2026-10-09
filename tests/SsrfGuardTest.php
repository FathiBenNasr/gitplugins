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

    /** L-1: special-purpose ranges PHP's filter flags let through are blocked too. */
    public function testSpecialPurposeRangesBlocked(): void
    {
        foreach ([
            '100.64.0.1', '100.127.255.254',   // CGNAT (shared address space)
            '198.18.0.1', '198.19.255.1',      // benchmarking
            '192.0.0.8', '192.0.2.10',         // IETF protocol assignments, TEST-NET-1
            '198.51.100.7', '203.0.113.9',     // TEST-NET-2/3
            '192.88.99.1',                     // 6to4 relay anycast
            '224.0.0.1', '239.255.255.250',    // multicast
            '255.255.255.255',                 // broadcast
            '64:ff9b::7f00:1', '64:ff9b::a00:1', '64:ff9b:1::a00:1', // NAT64 → loopback / RFC 1918
            '::7f00:1', '::127.0.0.1',         // IPv4-compatible
            '2002:7f00:1::', '2002:a00:1::1',  // 6to4 embedding 127/8 and 10/8
            '2001::1', '2001:0:4136:e378::1',  // Teredo
            '2001:db8::1',                     // documentation
            '100::1',                          // discard-only
            'fec0::1',                         // deprecated site-local
            'ff02::1',                         // IPv6 multicast
        ] as $ip) {
            self::assertTrue(PluginGitpluginsFetcher::isBlockedIp($ip), "expected blocked: {$ip}");
        }
    }

    public function testCidrMatcherBoundaries(): void
    {
        self::assertTrue(PluginGitpluginsFetcher::ipInCidr('100.64.0.0', '100.64.0.0/10'));
        self::assertTrue(PluginGitpluginsFetcher::ipInCidr('100.127.255.255', '100.64.0.0/10'));
        self::assertFalse(PluginGitpluginsFetcher::ipInCidr('100.128.0.0', '100.64.0.0/10'));
        self::assertFalse(PluginGitpluginsFetcher::ipInCidr('100.63.255.255', '100.64.0.0/10'));
        self::assertFalse(PluginGitpluginsFetcher::ipInCidr('::1', '10.0.0.0/8'), 'families never mix');
        self::assertFalse(PluginGitpluginsFetcher::ipInCidr('10.0.0.1', 'garbage'));
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
