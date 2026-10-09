<?php
/**
 * Git Plugin Installer — semver compare + tag normalisation (PURE, no I/O).
 *
 * Decides whether an available version is newer than an installed one and
 * refuses downgrades unless explicitly forced (A04 insecure-design guard).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsVersion
{
    /** Strip a leading 'v'/'V' and surrounding whitespace: "v1.2.3" → "1.2.3". */
    public static function normalise(string $tag): string
    {
        $tag = trim($tag);
        if ($tag !== '' && ($tag[0] === 'v' || $tag[0] === 'V')) {
            $tag = substr($tag, 1);
        }

        return $tag;
    }

    /**
     * True if $available is strictly newer than $installed (after normalisation).
     * Uses PHP's version_compare, which understands pre-release suffixes
     * (1.2.0-dev < 1.2.0). Empty/garbage installed → anything non-empty is newer.
     */
    public static function isNewer(string $available, string $installed): bool
    {
        $a = self::normalise($available);
        $i = self::normalise($installed);
        if ($a === '') {
            return false;
        }
        if ($i === '') {
            return true;
        }

        return version_compare($a, $i, '>');
    }

    /**
     * Pick the highest tag from a list (already-normalised compare). Returns the
     * ORIGINAL tag string (so the caller can fetch the archive at it). Null on
     * empty input.
     *
     * @param string[] $tags
     */
    public static function highest(array $tags): ?string
    {
        // A stable release wins over any pre-release (1.3.0-rc1, 2.0.0-beta…):
        // latest_tag must not pull a release candidate onto a production box.
        // Only when a repository has nothing but pre-releases is one picked.
        $best = null;
        $bestPre = null;
        foreach ($tags as $tag) {
            $tag = (string) $tag;
            if ($tag === '') {
                continue;
            }
            if (self::isPreRelease($tag)) {
                if ($bestPre === null || version_compare(self::normalise($tag), self::normalise($bestPre), '>')) {
                    $bestPre = $tag;
                }
            } elseif ($best === null || version_compare(self::normalise($tag), self::normalise($best), '>')) {
                $best = $tag;
            }
        }

        return $best ?? $bestPre;
    }

    /** PURE: does a tag carry a pre-release marker (dev, alpha, beta, RC, a "-suffix")? */
    public static function isPreRelease(string $tag): bool
    {
        $v = strtolower(self::normalise($tag));

        return str_contains($v, '-') || preg_match('/(dev|alpha|beta|rc|pre|snapshot)/', $v) === 1;
    }
}
