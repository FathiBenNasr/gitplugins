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
     * Build the candidate "release" API URLs for (provider, repo URL, ref) — PURE.
     *
     * Used by the `release` ref policy: instead of a git source-tarball, the source
     * publishes a pre-built plugin tarball as a release asset (so plugins that need
     * a composer/npm build step ship runnable). Given an optional tag ref we resolve
     * that exact release; otherwise the latest release. The fetcher calls these over
     * the SSRF-guarded client, reads the JSON, and downloads the picked `.tgz` asset
     * (or the release tarball as a fallback) — all the network lives there; this is
     * pure URL construction only, so it is unit-tested.
     *
     * GitHub : api.github.com/repos/{repo}/releases/{latest|tags/<tag>}
     * Forgejo/Gitea : {host}/api/v1/repos/{repo}/releases/{latest|tags/<tag>}
     * GitLab : {host}/api/v4/projects/{repo}/releases (latest is first; or /<tag>)
     *
     * @param  string   $provider one of github|gitlab|gitea|forgejo
     * @param  string   $url      https repo URL
     * @param  string   $ref      explicit tag, or '' for the latest release
     * @return string[] ordered candidate release-API URLs (possibly empty)
     */
    public static function releaseApiUrls(string $provider, string $url, string $ref = ''): array
    {
        $repo = self::repoPath($url);
        if ($repo === null) {
            return [];
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || $host === '') {
            return [];
        }

        $ref = trim($ref);
        // A caller-supplied tag must be a valid ref token; otherwise resolve latest.
        $hasTag = $ref !== '' && self::isValidRef($ref);
        $encRef = $hasTag ? rawurlencode($ref) : '';

        return match ($provider) {
            'github' => $hasTag
                ? ["https://api.github.com/repos/{$repo}/releases/tags/{$encRef}"]
                : ["https://api.github.com/repos/{$repo}/releases/latest"],
            'gitea', 'forgejo' => $hasTag
                ? [sprintf('https://%s/api/v1/repos/%s/releases/tags/%s', $host, $repo, $encRef)]
                : [sprintf('https://%s/api/v1/repos/%s/releases/latest', $host, $repo)],
            'gitlab' => $hasTag
                ? [sprintf('https://%s/api/v4/projects/%s/releases/%s', $host, rawurlencode($repo), $encRef)]
                : [sprintf('https://%s/api/v4/projects/%s/releases', $host, rawurlencode($repo))],
            default => [],
        };
    }

    /**
     * Pick the download URL of a built tarball asset from a parsed release-API
     * response — PURE (no network), so it is unit-tested.
     *
     * Accepts the decoded JSON of ONE release object (GitHub/Gitea/Forgejo) and
     * returns the browser-download URL of the first asset whose name ends in
     * `.tgz`/`.tar.gz`; falls back to the release's source `tarball_url` when no
     * such asset exists. Returns null when nothing usable is present.
     *
     * Asset shapes handled:
     *   GitHub : assets[].{name, browser_download_url}
     *   Gitea/Forgejo : assets[].{name, browser_download_url}
     *
     * @param array<string,mixed> $release decoded release object
     */
    public static function pickReleaseAsset(array $release): ?string
    {
        $assets = $release['assets'] ?? null;
        if (is_array($assets)) {
            foreach ($assets as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $name = strtolower((string) ($asset['name'] ?? ''));
                $dl   = (string) ($asset['browser_download_url'] ?? $asset['url'] ?? '');
                if ($dl !== '' && (str_ends_with($name, '.tgz') || str_ends_with($name, '.tar.gz'))) {
                    return $dl;
                }
            }
        }
        // Fallback: the release's own source tarball (GitHub/Gitea expose this).
        $fallback = (string) ($release['tarball_url'] ?? '');

        return $fallback !== '' ? $fallback : null;
    }

    /**
     * The version tag carried by a parsed release object (best-effort, PURE).
     * GitHub/Gitea/Forgejo expose `tag_name`; GitLab exposes `tag_name` too.
     */
    public static function releaseTag(array $release): string
    {
        return str_replace(["\r", "\n", "\0"], '', trim((string) ($release['tag_name'] ?? '')));
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

    /**
     * Build the API URL that returns a single branch's current HEAD commit SHA,
     * used by the track_branch policy to detect whether the branch has moved
     * (FIX 1: flag an update only when the SHA actually differs). Pure URL
     * construction only; the fetch (SSRF-guarded) lives in the cron. Returns null
     * for an invalid ref/host/provider.
     *
     * GitHub : api.github.com/repos/{repo}/branches/{branch}      → .commit.sha
     * Gitea/Forgejo : {host}/api/v1/repos/{repo}/branches/{branch} → .commit.id
     * GitLab : {host}/api/v4/projects/{repo}/repository/branches/{branch} → .commit.id
     */
    public static function branchShaApiUrl(string $provider, string $url, string $branch): ?string
    {
        if (!self::isValidRef($branch)) {
            return null;
        }
        $repo = self::repoPath($url);
        if ($repo === null) {
            return null;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || $host === '') {
            return null;
        }
        $encRef = rawurlencode($branch);

        return match ($provider) {
            'github'           => "https://api.github.com/repos/{$repo}/branches/{$encRef}",
            'gitlab'           => sprintf('https://%s/api/v4/projects/%s/repository/branches/%s', $host, rawurlencode($repo), $encRef),
            'gitea', 'forgejo' => sprintf('https://%s/api/v1/repos/%s/branches/%s', $host, $repo, $encRef),
            default            => null,
        };
    }

    /**
     * Extract the commit SHA from a decoded "single branch" API response — PURE.
     * Handles GitHub ({commit:{sha}}) and Gitea/Forgejo/GitLab ({commit:{id}}).
     * Returns '' when absent. CR/LF/NUL-stripped.
     *
     * @param array<string,mixed> $branch decoded branch object
     */
    public static function branchSha(array $branch): string
    {
        $commit = $branch['commit'] ?? null;
        $sha    = '';
        if (is_array($commit)) {
            $sha = (string) ($commit['sha'] ?? $commit['id'] ?? '');
        }

        return str_replace(["\r", "\n", "\0"], '', trim($sha));
    }
}
