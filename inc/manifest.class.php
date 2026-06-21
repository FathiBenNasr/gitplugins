<?php
/**
 * Git Plugin Installer — plugin.xml <gitupdate> manifest parser (PURE, no I/O).
 *
 * Every GLPI plugin in our suite declares its own git update source inside its
 * plugin.xml via a custom <gitupdate> element (GLPI core ignores unknown
 * elements). This parser reads that declaration from a plugin.xml STRING and
 * returns a normalised array, or null when the block is absent/malformed.
 *
 * It is side-effect-free (no DB, no filesystem, no network) so it is fully
 * unit-tested (tests/ManifestTest) — the I/O scan that locates plugin.xml files
 * lives in PluginGitpluginsDiscovery (lesson #12: separate pure from I/O).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsManifest
{
    /**
     * Parse the <gitupdate> declaration out of a plugin.xml string.
     *
     * Tolerant: trims values, strips CR/LF/NUL, lower-cases provider/ref_type,
     * coerces <private> to bool. The repo URL MUST be an http(s) URL with a host
     * — anything else (ftp, file, data, garbage) returns null (defence in depth;
     * the SSRF guard re-validates at fetch time anyway). Returns null when the
     * block is missing, the XML is malformed, or the repo is unusable.
     *
     * @return array{repo:string,ref:string,ref_type:string,provider:string,private:bool}|null
     */
    public static function parseXml(string $xml): ?array
    {
        $xml = trim($xml);
        if ($xml === '') {
            return null;
        }

        // Parse defensively — never let a libxml warning bubble up, never let
        // external entities load (XXE: A05/A10). LIBXML_NONET blocks network
        // entity fetches; we do not resolve entities ourselves.
        $prev = libxml_use_internal_errors(true);
        $doc  = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!($doc instanceof \SimpleXMLElement) || !isset($doc->gitupdate)) {
            return null;
        }

        $block = $doc->gitupdate;
        $repo  = self::scalar($block->repo ?? null);
        if ($repo === '') {
            return null;
        }

        // The repo URL must be http(s) with a host; reject everything else.
        $scheme = strtolower((string) parse_url($repo, PHP_URL_SCHEME));
        $host   = (string) parse_url($repo, PHP_URL_HOST);
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return null;
        }

        // Normalise via the existing source helpers where sensible: same URL
        // hygiene + provider detection used for managed sources, so a discovered
        // row and a hand-entered one resolve identically.
        $repo     = self::normaliseRepo($repo);
        $provider = self::scalar($block->provider ?? null);
        if ($provider === '' && class_exists('PluginGitpluginsSource')) {
            $provider = PluginGitpluginsSource::deriveProvider($repo);
        }
        $provider = strtolower($provider) ?: 'unknown';

        $refType = strtolower(self::scalar($block->ref_type ?? null));
        if (!in_array($refType, ['branch', 'tag', 'sha'], true)) {
            $refType = 'branch';
        }

        return [
            'repo'     => $repo,
            'ref'      => self::scalar($block->ref ?? null),
            'ref_type' => $refType,
            'provider' => $provider,
            'private'  => self::boolish(self::scalar($block->private ?? null)),
        ];
    }

    /**
     * Parse the descriptive fields of a plugin.xml string: <key>, <name>, the
     * latest version (highest <num> under <versions>, else top-level <version>),
     * PLUS the <gitupdate> declaration (or null when absent/malformed).
     *
     * PURE (no DB/FS/network), XXE-safe (LIBXML_NONET, no entity resolution).
     * Tolerant: missing fields come back as '' rather than throwing, so the
     * Detect-from-URL form can prefill whatever the manifest provides and leave
     * the rest to the admin. Returns null only when the XML is unparseable.
     *
     * @return array{key:string,name:string,version:string,gitupdate:array{repo:string,ref:string,ref_type:string,provider:string,private:bool}|null}|null
     */
    public static function parseInfo(string $xml): ?array
    {
        $xml = trim($xml);
        if ($xml === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $doc  = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!($doc instanceof \SimpleXMLElement)) {
            return null;
        }

        return [
            'key'       => self::scalar($doc->key ?? null),
            'name'      => self::scalar($doc->name ?? null),
            'version'   => self::latestVersion($doc),
            // Reuse the dedicated, well-tested gitupdate parser on the same string.
            'gitupdate' => self::parseXml($xml),
        ];
    }

    /**
     * Highest declared version: prefer the newest <num> under <versions>, else
     * fall back to a top-level <version>. Uses version_compare so 1.10 > 1.9.
     */
    private static function latestVersion(\SimpleXMLElement $doc): string
    {
        $best = '';
        if (isset($doc->versions->version)) {
            foreach ($doc->versions->version as $v) {
                $num = self::scalar($v->num ?? null);
                if ($num === '') {
                    continue;
                }
                if ($best === '' || version_compare($num, $best, '>')) {
                    $best = $num;
                }
            }
        }
        if ($best !== '') {
            return $best;
        }

        return self::scalar($doc->version ?? null);
    }

    /** Trim + strip CR/LF/NUL from a SimpleXML scalar node. '' when absent. */
    private static function scalar($node): string
    {
        if ($node === null) {
            return '';
        }

        return str_replace(["\r", "\n", "\0"], '', trim((string) $node));
    }

    /** Reuse the source URL normaliser when available; else a local fallback. */
    private static function normaliseRepo(string $url): string
    {
        if (class_exists('PluginGitpluginsSource')) {
            return PluginGitpluginsSource::normaliseUrl($url);
        }

        return rtrim(str_replace(["\r", "\n", "\0", ' '], '', trim($url)), '/');
    }

    /** Interpret a textual flag as bool: true/1/yes/on (case-insensitive). */
    private static function boolish(string $v): bool
    {
        return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true);
    }
}
