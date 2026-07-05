<?php
/**
 * Git Plugin Installer — multi-target deploy, PULL model (Phase 3).
 *
 * Owner-decision (2026-07-04): PULL, not push (spec Open Question 1). The origin
 * never reaches into a target to run code. Instead it EXPOSES a read-only,
 * HMAC-signed, SHA-pinned deploy manifest ("install plugin X at exactly this
 * ref"); each target's OWN gitplugins pulls it on its cron and installs through
 * the full Phase 0 pipeline (confirm/preflight/verify/rollback). No inbound
 * code-execution endpoint is added on any box — the only new endpoint serves
 * signed DATA.
 *
 * Security: mutual HMAC (per-target shared secret, GLPIKey-encrypted, never
 * logged/echoed), a freshness window (replay guard), and SHA pinning so a target
 * installs exactly what the origin resolved. The manifest carries no secrets —
 * only plugin keys, public repo URLs and resolved refs.
 *
 * buildManifest / canonicalise / sign / verify / isFresh / requestStringToSign
 * are PURE and unit-tested; exportForTarget / authenticate are the DB/HMAC glue.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsDeploy
{
    /** Replay window (seconds) a signed pull request timestamp may deviate. */
    public const SKEW_SECONDS = 300;

    /**
     * PURE: build the SHA-pinned deploy manifest from the origin's install-state
     * rows joined to their source rows. Only ACTIVE sources with a genuinely
     * installed version are exported, each pinned to the exact installed SHA when
     * known (ref_policy 'pin_sha'), else the installed version tag ('pin_tag').
     * Deterministic ordering (by plugin_key) so the canonical form is stable.
     *
     * @param array<int,array<string,mixed>> $installs rows: plugin_key, installed_version, installed_sha, plugin_gitplugins_sources_id
     * @param array<int,array<string,mixed>> $sources  rows keyed by id: url, provider, name, is_active
     * @return array<int,array{plugin_key:string,name:string,url:string,provider:string,ref_policy:string,ref:string}>
     */
    public static function buildManifest(array $installs, array $sources): array
    {
        // Index sources by id for the join.
        $byId = [];
        foreach ($sources as $s) {
            $byId[(int) ($s['id'] ?? 0)] = $s;
        }

        $out = [];
        foreach ($installs as $i) {
            $key       = trim((string) ($i['plugin_key'] ?? ''));
            $version   = trim((string) ($i['installed_version'] ?? ''));
            $sha       = trim((string) ($i['installed_sha'] ?? ''));
            $sourceId  = (int) ($i['plugin_gitplugins_sources_id'] ?? 0);
            if ($key === '' || !preg_match('/^[a-z0-9_]+$/', $key) || $version === '') {
                continue; // not installed / not a valid managed key
            }
            $src = $byId[$sourceId] ?? null;
            if ($src === null || (int) ($src['is_active'] ?? 0) !== 1) {
                continue; // no active source to pin against
            }
            $url = trim((string) ($src['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $out[] = [
                'plugin_key' => $key,
                'name'       => mb_substr(trim((string) ($src['name'] ?? $key)), 0, 255) ?: $key,
                'url'        => $url,
                'provider'   => (string) ($src['provider'] ?? 'unknown'),
                'ref_policy' => $sha !== '' ? 'pin_sha' : 'pin_tag',
                'ref'        => $sha !== '' ? $sha : $version,
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['plugin_key'], $b['plugin_key']));

        return $out;
    }

    /**
     * PURE: the canonical JSON body signed and served. A stable envelope
     * {version, generated_at, plugins:[…]} with sorted keys, so the same manifest
     * always hashes identically on both ends. generated_at is passed in (the pure
     * layer must not read the clock).
     *
     * @param array<int,array<string,mixed>> $manifest
     */
    public static function canonicalise(array $manifest, int $generatedAt): string
    {
        $envelope = [
            'generated_at' => $generatedAt,
            'plugins'      => array_map(static function (array $e): array {
                // Fixed key order for determinism.
                return [
                    'plugin_key' => (string) ($e['plugin_key'] ?? ''),
                    'name'       => (string) ($e['name'] ?? ''),
                    'provider'   => (string) ($e['provider'] ?? ''),
                    'ref'        => (string) ($e['ref'] ?? ''),
                    'ref_policy' => (string) ($e['ref_policy'] ?? ''),
                    'url'        => (string) ($e['url'] ?? ''),
                ];
            }, $manifest),
            'version'      => 1,
        ];

        return (string) json_encode($envelope, JSON_UNESCAPED_SLASHES);
    }

    /** PURE: hex HMAC-SHA256 of a body under a shared secret. '' when secret is empty. */
    public static function sign(string $body, string $secret): string
    {
        if ($secret === '') {
            return '';
        }

        return hash_hmac('sha256', $body, $secret);
    }

    /**
     * PURE: constant-time verification of a hex HMAC-SHA256 signature. False on any
     * empty input (fail closed) so a missing signature never authenticates.
     */
    public static function verify(string $body, string $secret, string $providedSig): bool
    {
        $providedSig = strtolower(trim($providedSig));
        if ($secret === '' || $providedSig === '') {
            return false;
        }
        $expected = self::sign($body, $secret);

        return $expected !== '' && hash_equals($expected, $providedSig);
    }

    /**
     * PURE: the canonical string a target signs to authenticate a pull request.
     * Binds the HTTP method, the request path, the timestamp and the target name,
     * so a signature can't be replayed against a different route/target.
     */
    public static function requestStringToSign(string $method, string $path, string $timestamp, string $target): string
    {
        return strtoupper(trim($method)) . "\n" . trim($path) . "\n" . trim($timestamp) . "\n" . trim($target);
    }

    /** PURE: is a request timestamp within the replay window of "now"? */
    public static function isFresh(int $timestamp, int $now, int $skew = self::SKEW_SECONDS): bool
    {
        if ($timestamp <= 0) {
            return false;
        }

        return abs($now - $timestamp) <= max(0, $skew);
    }

    // ---------------------------------------------------------------- live glue

    /**
     * Live: build the current signed manifest to serve to a target. Reads our own
     * installs + sources (no network), canonicalises with the given clock, and
     * signs with the target's shared secret. Returns [body, signature]. The clock
     * is injected so callers can keep the pure layer clock-free.
     *
     * @return array{0:string,1:string} [canonical body, hex signature]
     */
    public static function exportForTarget(string $secret, int $now): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $installs = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_gitplugins_installs']) as $i) {
            $installs[] = $i;
        }
        $sources = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_gitplugins_sources']) as $s) {
            $sources[] = $s;
        }

        $body = self::canonicalise(self::buildManifest($installs, $sources), $now);

        return [$body, self::sign($body, $secret)];
    }

    /**
     * Live: authenticate an inbound pull request against a registered target.
     * Looks up the (active) target by name, decrypts its secret, checks freshness
     * and the request HMAC. Returns the decrypted secret on success (so the caller
     * can sign the response), or '' on ANY failure (generic — no enumeration).
     */
    public static function authenticate(string $target, string $method, string $path, string $timestamp, string $signature, int $now): string
    {
        /** @var DBmysql $DB */
        global $DB;

        if ($target === '' || !$DB->tableExists('glpi_plugin_gitplugins_targets')) {
            return '';
        }
        if (!self::isFresh((int) $timestamp, $now)) {
            return '';
        }
        $row = $DB->request([
            'FROM'  => 'glpi_plugin_gitplugins_targets',
            'WHERE' => ['name' => $target, 'is_active' => 1],
            'LIMIT' => 1,
        ])->current();
        if ($row === null) {
            return '';
        }
        $secret = PluginGitpluginsSource::decryptCredential($row['secret'] ?? null);
        if ($secret === '') {
            return '';
        }
        $toSign = self::requestStringToSign($method, $path, $timestamp, $target);
        if (!self::verify($toSign, $secret, $signature)) {
            return '';
        }

        // Stamp last pull (best-effort audit).
        try {
            $DB->update('glpi_plugin_gitplugins_targets', ['last_pull_at' => date('Y-m-d H:i:s', $now)], ['id' => (int) $row['id']]);
        } catch (\Throwable $e) {
            // ignore
        }

        return $secret;
    }
}
