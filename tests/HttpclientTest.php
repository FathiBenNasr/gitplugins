<?php
/**
 * Pure tests for the ext-curl client that replaced Toolbox::getGuzzleClient()
 * (removed with Guzzle in GLPI 12). The network is replaced by an injected
 * transport and DNS by an injected resolver, so the A10 properties — per-hop
 * guard, IP pin, credential scoping, byte cap, redirect limit — are checked
 * without touching the wire.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/fetcher.class.php';
require_once __DIR__ . '/../inc/httpclient.class.php';

final class HttpclientTest extends TestCase
{
    private const ALLOWED = ['github.com', 'codeload.github.com'];

    /** @var array<int,array{url:string,opts:array<int,mixed>}> */
    private array $calls = [];

    /**
     * Transport replaying $responses in order: each is [status, redirect_url, body].
     */
    private function transport(array $responses): callable
    {
        $this->calls = [];

        return function (string $url, array $opts) use (&$responses): array {
            $this->calls[] = ['url' => $url, 'opts' => $opts];
            [$status, $redirect, $body] = array_shift($responses);
            $writer = $opts[CURLOPT_WRITEFUNCTION];
            $ok     = true;
            foreach (str_split($body === '' ? '' : $body, 4) as $chunk) {
                if ($chunk === '') {
                    continue;
                }
                if ($writer(null, $chunk) !== strlen($chunk)) {
                    $ok = false; // curl aborts the transfer on a short write
                    break;
                }
            }

            return ['ok' => $ok, 'status' => $status, 'redirect_url' => $redirect];
        };
    }

    private static function publicDns(): callable
    {
        return static fn (string $h): array => ['140.82.121.3'];
    }

    public function testPlainGetReturnsBodyAndPinsTheResolvedIp(): void
    {
        $r = PluginGitpluginsHttpclient::get('https://github.com/a/b', [
            'allowed_hosts' => self::ALLOWED,
            'resolver'      => self::publicDns(),
            'transport'     => $this->transport([[200, '', 'hello']]),
        ]);

        self::assertSame(['status' => 200, 'body' => 'hello'], $r);
        $o = $this->calls[0]['opts'];
        self::assertSame(['github.com:443:140.82.121.3'], $o[CURLOPT_RESOLVE]);
        self::assertFalse($o[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(CURLPROTO_HTTPS, $o[CURLOPT_PROTOCOLS]);
        self::assertTrue($o[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $o[CURLOPT_SSL_VERIFYHOST]);
    }

    public function testIpv6PinIsBracketed(): void
    {
        PluginGitpluginsHttpclient::get('https://github.com/a/b', [
            'allowed_hosts' => self::ALLOWED,
            'resolver'      => static fn (string $h): array => ['2606:4700:4700::1111'],
            'transport'     => $this->transport([[200, '', 'x']]),
        ]);
        self::assertSame(['github.com:443:[2606:4700:4700::1111]'], $this->calls[0]['opts'][CURLOPT_RESOLVE]);
    }

    public function testRedirectIsFollowedAndEachHopRevalidated(): void
    {
        $resolved = [];
        $r = PluginGitpluginsHttpclient::get('https://github.com/a/b/archive.tar.gz', [
            'allowed_hosts' => self::ALLOWED,
            'resolver'      => static function (string $h) use (&$resolved): array {
                $resolved[] = $h;
                return ['140.82.121.9'];
            },
            'transport'     => $this->transport([
                [302, 'https://codeload.github.com/a/b/tar.gz/v1', ''],
                [200, '', 'TARBALL'],
            ]),
        ]);

        self::assertSame('TARBALL', $r['body']);
        self::assertSame(['github.com', 'codeload.github.com'], $resolved);
        self::assertSame(['codeload.github.com:443:140.82.121.9'], $this->calls[1]['opts'][CURLOPT_RESOLVE]);
    }

    public function testRedirectToHostOutsideAllowlistIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        PluginGitpluginsHttpclient::get('https://github.com/a/b', [
            'allowed_hosts' => self::ALLOWED,
            'resolver'      => self::publicDns(),
            'transport'     => $this->transport([[302, 'https://evil.example/x', '']]),
        ]);
    }

    public function testRedirectToPrivateAddressIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        PluginGitpluginsHttpclient::get('https://github.com/a/b', [
            'allowed_hosts' => self::ALLOWED,
            // DNS rebinding: the second lookup lands on cloud metadata.
            'resolver'      => (static function (): callable {
                $n = 0;
                return static function (string $h) use (&$n): array {
                    return ++$n === 1 ? ['140.82.121.3'] : ['169.254.169.254'];
                };
            })(),
            'transport'     => $this->transport([[302, 'https://codeload.github.com/x', '']]),
        ]);
    }

    public function testRedirectDowngradeToHttpIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        PluginGitpluginsHttpclient::get('https://github.com/a/b', [
            'allowed_hosts' => self::ALLOWED,
            'resolver'      => self::publicDns(),
            'transport'     => $this->transport([[301, 'http://github.com/a/b', '']]),
        ]);
    }

    public function testRedirectLoopStopsAtTheLimit(): void
    {
        $loop = array_fill(0, 10, [302, 'https://github.com/a/b', '']);
        try {
            PluginGitpluginsHttpclient::get('https://github.com/a/b', [
                'allowed_hosts' => self::ALLOWED,
                'resolver'      => self::publicDns(),
                'transport'     => $this->transport($loop),
            ]);
            self::assertTrue(false, 'redirect loop must fail');
        } catch (\RuntimeException $e) {
            self::assertSame('fetch_failed', $e->getMessage());
        }
        self::assertSame(PluginGitpluginsHttpclient::MAX_REDIRECTS + 1, count($this->calls));
    }

    public function testBearerTokenIsNotForwardedToAnotherHost(): void
    {
        PluginGitpluginsHttpclient::get('https://github.com/a/b', [
            'allowed_hosts' => self::ALLOWED,
            'resolver'      => self::publicDns(),
            'headers'       => ['User-Agent' => 'GLPI-gitplugins', 'Authorization' => 'Bearer s3cret'],
            'transport'     => $this->transport([
                [302, 'https://codeload.github.com/a', ''],
                [200, '', 'ok'],
            ]),
        ]);

        self::assertContains('Authorization: Bearer s3cret', $this->calls[0]['opts'][CURLOPT_HTTPHEADER]);
        self::assertSame(['User-Agent: GLPI-gitplugins'], $this->calls[1]['opts'][CURLOPT_HTTPHEADER]);
    }

    public function testHeaderValuesCannotInjectNewLines(): void
    {
        self::assertSame(
            ['X-A: foobar'],
            PluginGitpluginsHttpclient::headersForHop(['X-A' => "foo\r\nbar"], 'h', 'h')
        );
    }

    public function testBodyOverTheCapAbortsWithTooLarge(): void
    {
        try {
            PluginGitpluginsHttpclient::get('https://github.com/a/b', [
                'allowed_hosts' => self::ALLOWED,
                'resolver'      => self::publicDns(),
                'max_bytes'     => 10,
                'transport'     => $this->transport([[200, '', str_repeat('A', 64)]]),
            ]);
            self::assertTrue(false, 'oversized body must fail');
        } catch (\RuntimeException $e) {
            self::assertSame('too_large', $e->getMessage());
        }
    }

    public function testBodyIsStreamedToTheSinkAndOnlyTheFinalHopKept(): void
    {
        $sink = fopen('php://memory', 'w+b');
        $r = PluginGitpluginsHttpclient::get('https://github.com/a/b', [
            'allowed_hosts' => self::ALLOWED,
            'resolver'      => self::publicDns(),
            'sink'          => $sink,
            'transport'     => $this->transport([
                [302, 'https://codeload.github.com/a', 'redirect page'],
                [200, '', 'PAYLOAD'],
            ]),
        ]);
        rewind($sink);

        self::assertSame('', $r['body']);
        self::assertSame('PAYLOAD', stream_get_contents($sink));
    }

    public function testErrorStatusIsReturnedNotFollowed(): void
    {
        $r = PluginGitpluginsHttpclient::get('https://github.com/a/b', [
            'allowed_hosts' => self::ALLOWED,
            'resolver'      => self::publicDns(),
            'transport'     => $this->transport([[404, '', 'nope']]),
        ]);
        self::assertSame(404, $r['status']);
    }

    public function testInitialUrlGoesThroughTheGuard(): void
    {
        $this->expectException(\RuntimeException::class);
        PluginGitpluginsHttpclient::get('https://evil.example/a', [
            'allowed_hosts' => self::ALLOWED,
            'resolver'      => self::publicDns(),
            'transport'     => $this->transport([[200, '', 'x']]),
        ]);
    }

    /**
     * L-1: behind GLPI's proxy the vetted IP must still bind the connection —
     * curl is told to CONNECT to that literal (the proxy no longer resolves the
     * name), while TLS keeps verifying the real host name.
     */
    public function testProxiedHopIsPinnedToTheVettedIp(): void
    {
        $GLOBALS['CFG_GLPI'] = ['proxy_name' => 'proxy.lan', 'proxy_port' => '3128'];
        try {
            PluginGitpluginsHttpclient::get('https://github.com/a/b', [
                'allowed_hosts' => self::ALLOWED,
                'resolver'      => self::publicDns(),
                'transport'     => $this->transport([[200, '', 'ok']]),
            ]);
        } finally {
            unset($GLOBALS['CFG_GLPI']);
        }
        $opts = $this->calls[0]['opts'];
        self::assertSame('http://proxy.lan:3128', $opts[CURLOPT_PROXY]);
        self::assertSame(['github.com:443:140.82.121.3:443'], $opts[CURLOPT_CONNECT_TO]);
        self::assertTrue($opts[CURLOPT_HTTPPROXYTUNNEL]);
        self::assertSame(2, $opts[CURLOPT_SSL_VERIFYHOST]);

        // IPv6 literals are bracketed; without a proxy nothing changes.
        $direct = PluginGitpluginsHttpclient::curlOptions([], 'github.com', '2606:50c0::1', 5, 5, [], static fn () => 0);
        self::assertSame(['github.com:443:[2606:50c0::1]'], $direct[CURLOPT_RESOLVE]);
        self::assertFalse(isset($direct[CURLOPT_CONNECT_TO]));
    }

    public function testProxyWithoutPortIsNotPortZero(): void
    {
        $o = PluginGitpluginsHttpclient::proxyOptions(['proxy_name' => 'proxy.lan', 'proxy_port' => '']);
        self::assertSame('http://proxy.lan', $o[CURLOPT_PROXY]);
    }

    public function testProxyOptionsMirrorGlpiConfiguration(): void
    {
        self::assertSame([], PluginGitpluginsHttpclient::proxyOptions([]));

        $o = PluginGitpluginsHttpclient::proxyOptions(
            ['proxy_name' => 'proxy.lan', 'proxy_port' => '3128', 'proxy_user' => 'u@x', 'proxy_passwd' => 'ENC'],
            static fn (string $s): string => $s === 'ENC' ? 'p:w' : ''
        );
        self::assertSame('http://proxy.lan:3128', $o[CURLOPT_PROXY]);
        self::assertSame('u%40x:p%3Aw', $o[CURLOPT_PROXYUSERPWD]);
    }
}
