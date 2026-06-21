<?php
/**
 * Git Plugin Installer — SSRF-guarded HTTPS tarball fetcher (A10 core).
 *
 * The server fetches admin-supplied URLs, so this is the plugin's highest-risk
 * surface. The guard (assertSafeUrl / isBlockedIp) is PURE and exhaustively
 * unit-tested (tests/SsrfGuardTest); the actual download (fetch) reuses GLPI's
 * proxy-aware Guzzle client and is exercised only on the live box.
 *
 * Defence in depth (A10): HTTPS only · host allowlist · resolve DNS and BLOCK
 * private / loopback / link-local (incl. 169.254.169.254 metadata) / ULA /
 * IPv4-mapped-IPv6 · reject userinfo + odd ports · re-validate every redirect ·
 * size cap · timeout · token sent as auth header (never in URL, never logged).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsFetcher
{
    /**
     * Decide whether a resolved IP literal sits in a blocked range. Pure +
     * fully tabled-tested (the SSRF backbone). Covers IPv4 private/loopback/
     * link-local + IPv6 loopback/link-local/ULA + IPv4-mapped-IPv6.
     */
    public static function isBlockedIp(string $ip): bool
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            // Not a valid literal → treat as blocked (fail closed).
            return true;
        }

        // Unwrap an IPv4-mapped IPv6 address (::ffff:169.254.169.254) and
        // re-check it as IPv4 — these tunnel SSRF past a naive IPv6 check.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $lower = strtolower($ip);
            if (str_starts_with($lower, '::ffff:')) {
                $tail = substr($ip, strrpos($ip, ':') + 1);
                if (filter_var($tail, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    return self::isBlockedIp($tail);
                }
            }
            // Block IPv6 loopback (::1), unspecified (::), link-local (fe80::/10)
            // and unique-local (fc00::/7) explicitly; reject the rest only if
            // they fail the public-range filter below.
            if ($lower === '::1' || $lower === '::') {
                return true;
            }
            $bin = inet_pton($ip);
            if ($bin !== false) {
                $first = ord($bin[0]);
                // fc00::/7 (ULA): first byte 0xFC or 0xFD.
                if (($first & 0xFE) === 0xFC) {
                    return true;
                }
                // fe80::/10 (link-local): 0xFE80..0xFEBF.
                if ($first === 0xFE && (ord($bin[1]) & 0xC0) === 0x80) {
                    return true;
                }
            }
        }

        // The authoritative check for public reachability: reject private &
        // reserved IPv4/IPv6 ranges. NO_PRIV_RANGE covers 10/8, 172.16/12,
        // 192.168/16, fc00::/7; NO_RES_RANGE covers 127/8, 169.254/16,
        // 0.0.0.0/8, 240/4, ::1, etc.
        $public = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        return $public === false;
    }

    /**
     * Validate a URL against the SSRF policy and return the resolved peer IPs to
     * pin (mitigating DNS-rebinding — the live fetch pins these via Guzzle's
     * resolve/connect options). Throws \RuntimeException with a GENERIC message
     * on any violation (no enumeration signal, no internal detail to the user).
     *
     * @param  string[]  $allowedHosts  exact host allowlist (lower-case)
     * @param  callable|null $resolver   host → string[] of IPs (injectable for
     *                                   tests; defaults to dns_get_record/gethostbynamel)
     * @return string[]  the resolved, validated peer IPs
     */
    public static function assertSafeUrl(string $url, array $allowedHosts, ?callable $resolver = null): array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException('unsafe_url');
        }
        // (1) HTTPS only.
        if (strtolower((string) $parts['scheme']) !== 'https') {
            throw new \RuntimeException('unsafe_url');
        }
        // (2) Reject embedded credentials (user@host) — an SSRF/obfuscation vector.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('unsafe_url');
        }
        // (3) Only the default https port (or none).
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new \RuntimeException('unsafe_url');
        }

        $host = strtolower((string) $parts['host']);
        // (4) Host allowlist (exact match — no subdomain wildcards by default).
        $allow = array_map('strtolower', $allowedHosts);
        if (!in_array($host, $allow, true)) {
            throw new \RuntimeException('host_not_allowed');
        }

        // (5) Resolve DNS and block every resolved address that is private /
        //     loopback / link-local / metadata. Resolve ONCE and return the IPs
        //     so the live fetch can pin them (DNS-rebinding mitigation).
        $ips = $resolver !== null ? $resolver($host) : self::resolveHost($host);
        if (!is_array($ips) || $ips === []) {
            throw new \RuntimeException('unsafe_url');
        }
        foreach ($ips as $ip) {
            if (self::isBlockedIp((string) $ip)) {
                throw new \RuntimeException('blocked_ip');
            }
        }

        return array_values(array_map('strval', $ips));
    }

    /** Resolve a host to A + AAAA records (live-box; not exercised in tests). */
    private static function resolveHost(string $host): array
    {
        // A literal IP host is "resolved" to itself.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }
        $ips = [];
        $v4  = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        $recs = @dns_get_record($host, DNS_AAAA);
        if (is_array($recs)) {
            foreach ($recs as $r) {
                if (!empty($r['ipv6'])) {
                    $ips[] = (string) $r['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * Resolve the download URL of a pre-built release tarball (.tgz) for the
     * `release` ref policy. Network step (SSRF-guarded, proxy-aware): queries the
     * provider's releases API, then delegates to the PURE pickReleaseAsset() to
     * choose the `.tgz` asset (or the source tarball as a fallback). The candidate
     * API URLs and asset selection are pure + unit-tested in RefResolver; the HTTP
     * call lives here. Live-box only.
     *
     * Returns [downloadUrl, resolvedTag]. Throws a GENERIC RuntimeException on any
     * failure (no upstream detail to the user). Both the API URL and the resolved
     * download URL are re-validated against the SSRF allowlist before any fetch.
     *
     * @param  string[] $allowedHosts host allowlist
     * @param  string   $token        optional bearer credential ('' = none)
     * @return array{0:string,1:string}
     */
    public static function resolveReleaseAsset(
        string $provider,
        string $url,
        string $ref,
        array $allowedHosts,
        string $token = '',
        int $timeout = 15
    ): array {
        $candidates = PluginGitpluginsRefResolver::releaseApiUrls($provider, $url, $ref);
        if ($candidates === []) {
            throw new \RuntimeException('invalid_ref');
        }

        $lastError = 'fetch_failed';
        foreach ($candidates as $apiUrl) {
            try {
                $body = self::fetchText($apiUrl, $allowedHosts, $token, 1048576, $timeout);
            } catch (\Throwable $e) {
                $lastError = 'fetch_failed';
                continue;
            }
            $data = json_decode($body, true);
            if (!is_array($data)) {
                continue;
            }
            // GitLab's "list releases" returns an array; take the first (latest).
            // A single-release endpoint returns the object directly.
            $release = (isset($data['tag_name']) || isset($data['assets']))
                ? $data
                : (isset($data[0]) && is_array($data[0]) ? $data[0] : null);
            if (!is_array($release)) {
                continue;
            }
            $download = PluginGitpluginsRefResolver::pickReleaseAsset($release);
            if ($download === null || $download === '') {
                continue;
            }
            // Re-validate the resolved download URL against the SSRF policy before
            // it is handed to fetch() (it may point at a different allowed host).
            self::assertSafeUrl($download, $allowedHosts);

            return [$download, PluginGitpluginsRefResolver::releaseTag($release)];
        }

        throw new \RuntimeException($lastError);
    }

    /**
     * Download the archive at $url to a unique temp file. Reuses GLPI's
     * proxy-aware Guzzle client (free $CFG_GLPI['proxy_*'] + GLPIKey compliance),
     * enforces timeout + size cap, sends any credential as a Bearer header (never
     * in the URL/query, never logged), and re-validates the URL on EVERY redirect.
     *
     * @param  string   $url           archive URL (already RefResolver-built)
     * @param  string[] $allowedHosts  host allowlist
     * @param  string   $token         optional bearer credential ('' = none)
     * @param  int      $maxBytes      hard size cap
     * @param  int      $timeout       seconds
     * @return string   path to the downloaded temp file
     */
    public static function fetch(
        string $url,
        array $allowedHosts,
        string $token = '',
        int $maxBytes = 52428800,
        int $timeout = 30
    ): string {
        // Guard before any network touch; pin the resolved peer IPs.
        $ips    = self::assertSafeUrl($url, $allowedHosts);
        $host   = strtolower((string) parse_url($url, PHP_URL_HOST));
        $tmpDir = defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir();
        $dest   = $tmpDir . '/gitplugins_' . bin2hex(random_bytes(8)) . '.tar.gz';

        $headers = ['User-Agent' => 'GLPI-gitplugins'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token; // never logged
        }

        $client  = \Toolbox::getGuzzleClient();
        $written = 0;
        $sink    = fopen($dest, 'wb');
        if ($sink === false) {
            throw new \RuntimeException('fetch_failed');
        }

        try {
            $client->request('GET', $url, [
                'headers'         => $headers,
                'connect_timeout' => min($timeout, 30),
                'timeout'         => $timeout,
                'allow_redirects' => [
                    'max'             => 5,
                    'strict'          => true,
                    'referer'         => false,
                    'protocols'       => ['https'],
                    // Re-validate every redirect target against the SSRF policy.
                    'on_redirect'     => static function ($request, $response, $uri) use ($allowedHosts): void {
                        self::assertSafeUrl((string) $uri, $allowedHosts);
                    },
                ],
                // Pin the first resolved IP for the apex host (DNS-rebinding
                // mitigation); redirects to other allowed hosts re-resolve safely.
                'curl'            => [CURLOPT_RESOLVE => [$host . ':443:' . $ips[0]]],
                'sink'            => $sink,
                'progress'        => static function ($dlTotal, $dlNow) use (&$written, $maxBytes): void {
                    $written = (int) $dlNow;
                    if ($dlTotal > $maxBytes || $dlNow > $maxBytes) {
                        throw new \RuntimeException('too_large');
                    }
                },
            ]);
        } catch (\Throwable $e) {
            if (is_resource($sink)) {
                fclose($sink);
            }
            @unlink($dest);
            // Generic message — no upstream detail leaks to the caller/user.
            throw new \RuntimeException('fetch_failed');
        }

        if (is_resource($sink)) {
            fclose($sink);
        }
        if (!is_file($dest) || filesize($dest) === 0 || filesize($dest) > $maxBytes) {
            @unlink($dest);
            throw new \RuntimeException('fetch_failed');
        }

        return $dest;
    }

    /**
     * Fetch a small text resource (a plugin.xml manifest) into memory, reusing
     * the SAME SSRF guard (assertSafeUrl), proxy-aware Guzzle client, redirect
     * re-validation, timeout and size cap as fetch(). Used by Detect-from-URL —
     * the ONE place an outbound call is made on user input, behind the right +
     * allowlist. Returns the body string, or throws a GENERIC RuntimeException.
     *
     * Distinct from fetch() because we want the bytes in memory (no temp file)
     * and a much smaller cap (a manifest is a few KB, not a tarball).
     *
     * @param  string[] $allowedHosts host allowlist
     * @param  string   $token        optional bearer credential ('' = none)
     * @param  int      $maxBytes     hard size cap (small; manifests are tiny)
     * @param  int      $timeout      seconds
     */
    public static function fetchText(
        string $url,
        array $allowedHosts,
        string $token = '',
        int $maxBytes = 524288,
        int $timeout = 15
    ): string {
        // Guard before any network touch; pin the resolved peer IP (rebinding).
        $ips  = self::assertSafeUrl($url, $allowedHosts);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        $headers = ['User-Agent' => 'GLPI-gitplugins'];
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token; // never logged
        }

        $client = \Toolbox::getGuzzleClient();
        try {
            $resp = $client->request('GET', $url, [
                'headers'         => $headers,
                'connect_timeout' => min($timeout, 30),
                'timeout'         => $timeout,
                'allow_redirects' => [
                    'max'         => 5,
                    'strict'      => true,
                    'referer'     => false,
                    'protocols'   => ['https'],
                    'on_redirect' => static function ($request, $response, $uri) use ($allowedHosts): void {
                        self::assertSafeUrl((string) $uri, $allowedHosts);
                    },
                ],
                'curl'            => [CURLOPT_RESOLVE => [$host . ':443:' . $ips[0]]],
                // Hard byte cap: read at most $maxBytes from the stream.
                'stream'          => true,
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('fetch_failed');
        }

        if ($resp->getStatusCode() !== 200) {
            throw new \RuntimeException('fetch_failed');
        }
        $body = $resp->getBody();
        $out  = '';
        while (!$body->eof() && strlen($out) <= $maxBytes) {
            $out .= $body->read(8192);
        }
        if ($out === '' || strlen($out) > $maxBytes) {
            throw new \RuntimeException('fetch_failed');
        }

        return $out;
    }
}
