<?php
/**
 * Git Plugin Installer — ref → archive-URL + API-URL builder (PURE, no network).
 *
 * Maps a (provider, repo URL, ref) onto the host's tarball-download endpoint
 * and tags API endpoint. No git binary: we fetch HTTPS tarballs so no .git
 * directory ever lands in plugins/ (which GLPI core's VCS guard would reject).
 *
 * Every method here is side-effect-free and unit-tested (tests/RefResolverTest).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsRefResolver
{
    /**
     * Validate a git ref token (branch / tag / SHA). Rejects anything that could
     * traverse or inject: no '..', no whitespace/CRLF, no leading '/' or '-'.
     */
    public static function isValidRef(string $ref): bool
    {
        $ref = trim($ref);
        if ($ref === '' || strlen($ref) > 200) {
            return false;
        }
        if (str_contains($ref, '..')) {
            return false;
        }
        if ($ref[0] === '/' || $ref[0] === '-') {
            return false;
        }

        // Allow alnum, dot, underscore, slash, hyphen only (git ref-ish subset).
        return (bool) preg_match('#^[A-Za-z0-9._/\-]+$#', $ref);
    }

    /** Looks like a 7–40 char hex commit SHA. */
    public static function isSha(string $ref): bool
    {
        return (bool) preg_match('/^[0-9a-f]{7,40}$/i', trim($ref));
    }

    /**
     * Owner/repo path from an HTTPS repo URL, e.g.
     *   https://github.com/foo/bar(.git) → "foo/bar".
     * Returns null if the path is empty / malformed.
     */
    public static function repoPath(string $url): ?string
    {
        $path = (string) parse_url(trim($url), PHP_URL_PATH);
        $path = trim($path, '/');
        if ($path === '') {
            return null;
        }
        // Drop a trailing ".git".
        if (str_ends_with($path, '.git')) {
            $path = substr($path, 0, -4);
        }
        // Reject traversal / empty segments.
        if (str_contains($path, '..') || !preg_match('#^[A-Za-z0-9._/\-]+$#', $path)) {
            return null;
        }

        return $path !== '' ? $path : null;
    }

    /**
     * Build the tarball download URL for (provider, repo URL, ref).
     * Returns null when inputs are invalid (caller maps to a generic error).
     */
    public static function archiveUrl(string $provider, string $url, string $ref): ?string
    {
        if (!self::isValidRef($ref)) {
            return null;
        }
        $repo = self::repoPath($url);
        if ($repo === null) {
            return null;
        }
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);
        $host   = (string) parse_url($url, PHP_URL_HOST);
        if (strtolower($scheme) !== 'https' || $host === '') {
            return null;
        }
        $encRef = rawurlencode($ref);

        return match ($provider) {
            // codeload serves the actual tarball; github.com/<repo>/archive 302s here.
            'github'          => "https://codeload.github.com/{$repo}/tar.gz/{$encRef}",
            'gitlab'          => sprintf(
                'https://%s/%s/-/archive/%s/%s-%s.tar.gz',
                $host,
                $repo,
                $encRef,
                rawurlencode(basename($repo)),
                $encRef
            ),
            // Gitea and Forgejo share the same archive endpoint shape.
            'gitea', 'forgejo' => sprintf('https://%s/%s/archive/%s.tar.gz', $host, $repo, $encRef),
            default            => null,
        };
    }

    /**
     * Build the candidate raw-plugin.xml URLs for (provider, repo URL, ref).
     * PURE: pure URL construction, no network. The Detect-from-URL flow tries
     * each in order and stops at the first 200 (so a Forgejo/Gitea host gets both
     * the /raw/branch/{ref}/ and /raw/{ref}/ shapes; an empty ref expands to the
     * usual default branch candidates HEAD/main/master).
     *
     * A trailing ".git" is stripped (repoPath handles it). Returns [] when inputs
     * are invalid (caller maps to a generic "could not detect" error).
     *
     * @param  string   $provider one of github|gitlab|gitea|forgejo
     * @param  string   $url      https repo URL
     * @param  string   $ref      explicit ref, or '' to try the default branches
     * @return string[] ordered candidate raw URLs (possibly empty)
     */
    public static function rawManifestUrls(string $provider, string $url, string $ref = ''): array
    {
        $repo = self::repoPath($url);
        if ($repo === null) {
            return [];
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host   = (string) parse_url($url, PHP_URL_HOST);
        if ($scheme !== 'https' || $host === '') {
            return [];
        }

        // Build the ordered ref candidate list. A caller-supplied ref must be
        // valid; otherwise (or when empty) fall back to HEAD/main/master.
        $refs = [];
        $ref  = trim($ref);
        if ($ref !== '' && self::isValidRef($ref)) {
            $refs[] = $ref;
        }
        foreach (['HEAD', 'main', 'master'] as $d) {
            if (!in_array($d, $refs, true)) {
                $refs[] = $d;
            }
        }

        $out = [];
        foreach ($refs as $r) {
            $encRef     = rawurlencode($r);
            $candidates = match ($provider) {
                'github'           => ["https://raw.githubusercontent.com/{$repo}/{$encRef}/plugin.xml"],
                'gitlab'           => [sprintf('https://%s/%s/-/raw/%s/plugin.xml', $host, $repo, $encRef)],
                // Forgejo/Gitea: newer hosts want /raw/branch/{ref}/…; older ones
                // and tags/SHAs resolve via the bare /raw/{ref}/… — try both.
                'gitea', 'forgejo' => [
                    sprintf('https://%s/%s/raw/branch/%s/plugin.xml', $host, $repo, $encRef),
                    sprintf('https://%s/%s/raw/%s/plugin.xml', $host, $repo, $encRef),
                ],
                default            => [],
            };
            foreach ($candidates as $candidate) {
                if (!in_array($candidate, $out, true)) {
                    $out[] = $candidate;
                }
            }
        }

        return $out;
    }

    /**
     * Build the "list tags" API URL used (over the network, in Phase 4) to
     * resolve latest_tag and ref→SHA. Pure URL construction only.
     */
    public static function tagsApiUrl(string $provider, string $url): ?string
    {
        $repo = self::repoPath($url);
        if ($repo === null) {
            return null;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return null;
        }

        return match ($provider) {
            'github'           => "https://api.github.com/repos/{$repo}/tags",
            'gitlab'           => sprintf('https://%s/api/v4/projects/%s/repository/tags', $host, rawurlencode($repo)),
            'gitea', 'forgejo' => sprintf('https://%s/api/v1/repos/%s/tags', $host, $repo),
            default            => null,
        };
    }
}
