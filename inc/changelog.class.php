<?php
/**
 * Git Plugin Installer — changelog surfacing (Phase 8).
 *
 * Every in-house plugin keeps a Keep-a-Changelog-style CHANGELOG.md. On the
 * install-confirm screen we fetch it at the resolved ref (SSRF-guarded, size-
 * capped) and show ONLY the sections between the installed and available
 * versions, so an admin sees exactly what a click will change.
 *
 * The parsing + slicing (parseSections / sliceBetween) are PURE and unit-tested;
 * fetchFor() is the SSRF-guarded network step (live-box only).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsChangelog
{
    /** Hard cap on how many version sections we ever surface (avoid a wall of text). */
    private const MAX_SECTIONS = 20;

    /**
     * PURE: split a CHANGELOG.md into version-headed sections, in document order.
     *
     * A heading is a markdown `#`/`##`/`###` line carrying a version, in any of the
     * common shapes: `## [1.2.3] - 2024-01-01`, `## 1.2.3`, `## v1.2.3 (2024…)`.
     * Each section spans from its heading up to (not including) the next version
     * heading. `version` is the normalised version; `text` is the raw slice
     * (heading + body). Non-version headings (## Unreleased, ## [Keep a Changelog])
     * are ignored as section starts — their text folds into the preceding section
     * (or is dropped if before the first versioned one).
     *
     * @return array<int,array{version:string,text:string}>
     */
    public static function parseSections(string $md): array
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);
        $lines = explode("\n", $md);

        $sections = [];
        $current  = null; // ['version'=>, 'lines'=>[]]
        foreach ($lines as $line) {
            $ver = self::versionOfHeading($line);
            if ($ver !== null) {
                if ($current !== null) {
                    $sections[] = ['version' => $current['version'], 'text' => rtrim(implode("\n", $current['lines'])) . "\n"];
                }
                $current = ['version' => $ver, 'lines' => [$line]];
            } elseif ($current !== null) {
                $current['lines'][] = $line;
            }
        }
        if ($current !== null) {
            $sections[] = ['version' => $current['version'], 'text' => rtrim(implode("\n", $current['lines'])) . "\n"];
        }

        return $sections;
    }

    /**
     * PURE: the normalised version carried by a markdown heading line, or null if
     * the line is not a version heading. Accepts 1–3 leading '#', an optional
     * '[...]' bracket, an optional leading 'v', and requires a digit-led version.
     */
    private static function versionOfHeading(string $line): ?string
    {
        if (preg_match('/^\s{0,3}#{1,3}\s+/', $line) !== 1) {
            return null;
        }
        // After the hashes: optional '[', optional v/V, then a version token.
        if (preg_match('/^\s{0,3}#{1,3}\s+\[?\s*[vV]?\s*(\d+(?:\.\d+){0,3}(?:[-.][0-9A-Za-z.]+)?)/', $line, $m) !== 1) {
            return null;
        }

        return PluginGitpluginsVersion::normalise($m[1]);
    }

    /**
     * PURE: the changelog slice an admin should see for installed → available.
     *
     * Includes every section with version V such that:
     *   (installed === '' OR V > installed)  AND  (available === '' OR V <= available)
     *
     * Fresh install (installed === '') with a known available → sections up to and
     * including available. Unknown available (release policy, resolved at fetch) →
     * upper bound is open, so the newest sections above installed are shown. When
     * nothing matches (e.g. no headings parsed, or bounds exclude all) returns ''.
     * Capped at MAX_SECTIONS newest sections.
     */
    public static function sliceBetween(string $md, string $installed, string $available): string
    {
        $installed = PluginGitpluginsVersion::normalise($installed);
        $available = PluginGitpluginsVersion::normalise($available);

        $matched = [];
        foreach (self::parseSections($md) as $sec) {
            $v = $sec['version'];
            if ($v === '') {
                continue;
            }
            if ($installed !== '' && version_compare($v, $installed, '<=')) {
                continue; // already have it
            }
            if ($available !== '' && version_compare($v, $available, '>')) {
                continue; // beyond what we're installing
            }
            $matched[] = $sec['text'];
            if (count($matched) >= self::MAX_SECTIONS) {
                break;
            }
        }

        return $matched === [] ? '' : rtrim(implode("\n", $matched)) . "\n";
    }

    /**
     * Live: fetch CHANGELOG.md at the resolved ref (SSRF-guarded, small cap) and
     * return the sliced sections between installed and available. Best-effort —
     * any failure (no changelog, blocked host, parse miss) returns ''. Never
     * throws into the confirm screen. Local sources read from disk instead.
     */
    public static function fetchFor(array $source, string $ref, string $installed, string $available): string
    {
        try {
            $provider = (string) ($source['provider'] ?? 'unknown');
            $url      = (string) ($source['url'] ?? '');
            $cfg      = PluginGitpluginsConfig::singleton();

            $raw = $provider === 'local'
                ? self::readLocalChangelog($url, $cfg)
                : self::fetchRemoteChangelog($provider, $url, $ref, $source, $cfg);
            if ($raw === '') {
                return '';
            }

            return self::sliceBetween($raw, $installed, $available);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** SSRF-guarded fetch of the first resolvable CHANGELOG.md candidate. '' on miss. */
    private static function fetchRemoteChangelog(string $provider, string $url, string $ref, array $source, PluginGitpluginsConfig $cfg): string
    {
        $token = PluginGitpluginsSource::decryptCredential($source['credential'] ?? null);
        foreach (['CHANGELOG.md', 'CHANGELOG', 'changelog.md'] as $file) {
            foreach (PluginGitpluginsRefResolver::rawFileUrls($provider, $url, $ref, $file) as $candidate) {
                try {
                    $body = PluginGitpluginsFetcher::fetchText(
                        $candidate,
                        $cfg->getAllowedHosts(),
                        $token,
                        262144,
                        $cfg->getFetchTimeoutSeconds()
                    );
                    if (trim($body) !== '') {
                        return $body;
                    }
                } catch (\Throwable $e) {
                    // try the next candidate
                }
            }
        }

        return '';
    }

    /** Read a LOCAL source's CHANGELOG.md from disk (gated + allowlisted). '' on miss. */
    private static function readLocalChangelog(string $path, PluginGitpluginsConfig $cfg): string
    {
        if (!$cfg->allowLocalSources()
            || !PluginGitpluginsLocalsource::pathAllowed($path, $cfg->getLocalSourceRoots())) {
            return '';
        }
        $real = @realpath($path);
        if ($real === false) {
            return '';
        }
        foreach (['CHANGELOG.md', 'CHANGELOG', 'changelog.md'] as $file) {
            $fp = $real . '/' . $file;
            if (is_file($fp)) {
                $body = @file_get_contents($fp, false, null, 0, 262144);
                if (is_string($body) && trim($body) !== '') {
                    return $body;
                }
            }
        }

        return '';
    }
}
