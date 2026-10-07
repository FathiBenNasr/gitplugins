<?php
/**
 * Git Plugin Installer — minimal HTTPS GET client on ext-curl.
 *
 * Replaces Toolbox::getGuzzleClient(): GLPI 11 bundles Guzzle but not
 * symfony/http-client, GLPI 12 removes Guzzle and ships only
 * Glpi\Toolbox\HttpClient (Symfony). ext-curl is the one transport present on
 * both, and it keeps the A10 controls in OUR hands rather than in an HTTP
 * library's redirect policy:
 *
 *  - redirects are never followed by curl; each hop is re-validated with
 *    PluginGitpluginsFetcher::assertSafeUrl() and its resolved IP pinned
 *    (CURLOPT_RESOLVE — DNS-rebinding mitigation);
 *  - HTTPS only, peer + host verification on;
 *  - the Authorization header is dropped as soon as a hop leaves the first host;
 *  - hard byte cap enforced while streaming (the transfer is aborted, not
 *    truncated after the fact);
 *  - GLPI's outbound proxy ($CFG_GLPI['proxy_*'], GLPIKey-encrypted password)
 *    is honoured, as getGuzzleClient() did.
 *
 * Errors surface as \RuntimeException with a GENERIC message ('fetch_failed',
 * 'too_large', or the guard's own codes) — no upstream detail reaches the user.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsHttpclient
{
    public const MAX_REDIRECTS = 5;

    /**
     * GET $url. Returns ['status' => int, 'body' => string]; the body is '' when
     * it was streamed to $opts['sink'].
     *
     * @param array{
     *   allowed_hosts: string[],
     *   headers?: array<string,string>,
     *   timeout?: int,
     *   connect_timeout?: int,
     *   max_bytes?: int,
     *   sink?: resource,
     *   max_redirects?: int,
     *   resolver?: callable,
     *   transport?: callable
     * } $opts  `resolver` and `transport` are test seams only.
     * @return array{status:int, body:string}
     */
    public static function get(string $url, array $opts): array
    {
        $allowed   = $opts['allowed_hosts'];
        $headers   = $opts['headers'] ?? [];
        $maxBytes  = (int) ($opts['max_bytes'] ?? 10485760);
        $maxHops   = (int) ($opts['max_redirects'] ?? self::MAX_REDIRECTS);
        $sink      = $opts['sink'] ?? null;
        $transport = $opts['transport'] ?? [self::class, 'curlTransport'];
        $firstHost = strtolower((string) parse_url($url, PHP_URL_HOST));

        for ($hop = 0; ; $hop++) {
            // Every hop — the first one included — goes through the SSRF guard.
            $ips  = PluginGitpluginsFetcher::assertSafeUrl($url, $allowed, $opts['resolver'] ?? null);
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            if (is_resource($sink)) {
                ftruncate($sink, 0);
                rewind($sink);
            }
            $body    = '';
            $written = 0;
            $writer  = self::writer($sink, $body, $written, $maxBytes);

            $res = $transport($url, self::curlOptions(
                self::headersForHop($headers, $firstHost, $host),
                $host,
                $ips[0],
                (int) ($opts['timeout'] ?? 30),
                (int) ($opts['connect_timeout'] ?? min((int) ($opts['timeout'] ?? 30), 30)),
                self::proxyOptions($GLOBALS['CFG_GLPI'] ?? []),
                $writer
            ));

            if ($written > $maxBytes) {
                throw new \RuntimeException('too_large');
            }
            if (!$res['ok']) {
                throw new \RuntimeException('fetch_failed');
            }

            $status = (int) $res['status'];
            if ($status >= 300 && $status < 400 && $status !== 304) {
                if ($hop >= $maxHops || (string) ($res['redirect_url'] ?? '') === '') {
                    throw new \RuntimeException('fetch_failed');
                }
                $url = (string) $res['redirect_url'];
                continue;
            }

            return ['status' => $status, 'body' => $body];
        }
    }

    /**
     * Headers for one hop as curl "Name: value" lines. The credential stays
     * with the host it was configured for: a redirect to another host (e.g. a
     * CDN) must not receive the bearer token.
     *
     * @param array<string,string> $headers
     * @return string[]
     */
    public static function headersForHop(array $headers, string $firstHost, string $hopHost): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0 && $hopHost !== $firstHost) {
                continue;
            }
            // Header injection guard: no CR/LF may reach the wire.
            $lines[] = $name . ': ' . str_replace(["\r", "\n"], '', $value);
        }

        return $lines;
    }

    /**
     * curl options for GLPI's configured outbound proxy, as getGuzzleClient()
     * built them. Empty when no proxy is configured.
     *
     * @param array<string,mixed> $cfg      $CFG_GLPI
     * @param callable|null       $decrypt  GLPIKey decrypt (test seam)
     * @return array<int,mixed>
     */
    public static function proxyOptions(array $cfg, ?callable $decrypt = null): array
    {
        if (empty($cfg['proxy_name'])) {
            return [];
        }
        $opts = [
            CURLOPT_PROXY     => 'http://' . $cfg['proxy_name'] . ':' . (int) ($cfg['proxy_port'] ?? 0),
            CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
        ];
        if (!empty($cfg['proxy_user'])) {
            $decrypt ??= static fn (string $s): string => class_exists('GLPIKey')
                ? (string) (new \GLPIKey())->decrypt($s)
                : '';
            $opts[CURLOPT_PROXYUSERPWD] = rawurlencode((string) $cfg['proxy_user']) . ':'
                . rawurlencode($decrypt((string) ($cfg['proxy_passwd'] ?? '')));
        }

        return $opts;
    }

    /**
     * Write callback enforcing the byte cap while streaming: returning a short
     * count makes curl abort the transfer.
     *
     * @param resource|null $sink
     */
    public static function writer($sink, string &$body, int &$written, int $maxBytes): \Closure
    {
        return static function ($ch, string $chunk) use ($sink, &$body, &$written, $maxBytes): int {
            $len      = strlen($chunk);
            $written += $len;
            if ($written > $maxBytes) {
                return 0;
            }
            if (is_resource($sink)) {
                return fwrite($sink, $chunk) === $len ? $len : 0;
            }
            $body .= $chunk;

            return $len;
        };
    }

    /**
     * @param string[]         $headerLines
     * @param array<int,mixed> $proxy
     * @return array<int,mixed>
     */
    public static function curlOptions(
        array $headerLines,
        string $host,
        string $ip,
        int $timeout,
        int $connectTimeout,
        array $proxy,
        \Closure $writer
    ): array {
        // CURLOPT_RESOLVE wants IPv6 literals in brackets.
        $pin = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;

        return [
            CURLOPT_HTTPGET        => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE        => [$host . ':443:' . $pin],
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_TIMEOUT        => max(1, $timeout),
            CURLOPT_CONNECTTIMEOUT => max(1, $connectTimeout),
            CURLOPT_WRITEFUNCTION  => $writer,
        ] + $proxy;
    }

    /**
     * The live transport (not unit-tested: network).
     *
     * @param array<int,mixed> $curlOpts
     * @return array{ok:bool, status:int, redirect_url:string}
     */
    public static function curlTransport(string $url, array $curlOpts): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'redirect_url' => ''];
        }
        curl_setopt_array($ch, $curlOpts);
        $ok = curl_exec($ch) !== false;

        return [
            'ok'           => $ok,
            'status'       => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            // Computed by curl from Location even with FOLLOWLOCATION off.
            'redirect_url' => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL),
        ];
    }
}
