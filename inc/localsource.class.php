<?php
/**
 * Git Plugin Installer — LOCAL / dev source acquire step (Phase 1).
 *
 * Registers a source that points at a local absolute path (a working dir) and
 * syncs + reinstalls on demand — generalising our hand-rolled per-plugin
 * deploy.sh. It is just a different *acquire* step: instead of fetch+extract it
 * copies the path into a staged dir, then the entire Phase 0 pipeline (preflight
 * → build → locale → atomic place → snapshot → verify → rollback) runs unchanged.
 *
 * SECURITY: local sources read the server filesystem, so they are gated hard —
 * OFF by default (`allow_local_sources`), and the path MUST sit under an
 * admin-configured root allowlist (`local_source_roots`). The in-allowlist
 * decision is PURE (lexical, unit-tested); copyToStaged re-checks with the REAL
 * canonical path (realpath) as defence-in-depth against symlink/`..` escapes.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsLocalsource
{
    /**
     * PURE lexical normalisation: collapse `//`, resolve `.` / `..` segments,
     * strip a trailing slash. Returns '' for a non-absolute path (we only ever
     * allow absolute local sources). No filesystem access.
     */
    public static function normalisePath(string $path): string
    {
        $path = str_replace("\0", '', trim($path));
        if ($path === '' || $path[0] !== '/') {
            return '';
        }
        $out = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out); // may pop below root → caught by allowlist check
                continue;
            }
            $out[] = $seg;
        }

        return '/' . implode('/', $out);
    }

    /**
     * PURE: is $path within one of the configured roots? Both sides are
     * lexically normalised; $path must EQUAL a root or sit strictly beneath it
     * (a `/srv/plugins` root does NOT authorise `/srv/plugins-evil`). Empty roots
     * → nothing allowed (fail closed). Unit-tested.
     *
     * @param string[] $roots
     */
    public static function pathAllowed(string $path, array $roots): bool
    {
        $p = self::normalisePath($path);
        if ($p === '') {
            return false;
        }
        foreach ($roots as $root) {
            $r = self::normalisePath((string) $root);
            if ($r === '' || $r === '/') {
                continue; // never allow the whole filesystem as a root
            }
            if ($p === $r || strncmp($p, $r . '/', strlen($r) + 1) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copy a validated local plugin path into a fresh staged dir (live-box: FS).
     * Mirrors Extractor::extractTo's contract — returns the staged dir (named
     * $key, containing setup.php). Re-validates the REAL canonical path against
     * the allowlist, skips VCS metadata and symlinks. Throws on any failure so
     * run() records a generic error and aborts before touching plugins/.
     *
     * @param string[] $roots configured local_source_roots
     */
    public static function copyToStaged(string $srcPath, string $key, array $roots): string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            throw new \RuntimeException('local_acquire_failed');
        }
        // Defence-in-depth: resolve the REAL path (follows symlinks) and re-check
        // it is still inside the allowlist — a symlinked path that passed the
        // lexical check must not escape.
        $real = @realpath($srcPath);
        if ($real === false || !is_dir($real) || !self::pathAllowed($real, $roots)) {
            throw new \RuntimeException('local_acquire_failed');
        }
        if (!is_file($real . '/setup.php')) {
            throw new \RuntimeException('local_acquire_failed');
        }

        $tmpBase = defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir();
        $staging = $tmpBase . '/gitplugins_l_' . bin2hex(random_bytes(8));
        $staged  = $staging . '/' . $key;
        if (!@mkdir($staged, 0o750, true)) {
            throw new \RuntimeException('local_acquire_failed');
        }
        try {
            self::rcopy($real, $staged);
            foreach (['.git', '.hg', '.svn'] as $vcs) {
                if (is_dir($staged . '/' . $vcs)) {
                    self::rrmdir($staged . '/' . $vcs);
                }
            }
            if (!is_file($staged . '/setup.php')) {
                throw new \RuntimeException('local_acquire_failed');
            }

            return $staged;
        } catch (\Throwable $e) {
            self::rrmdir($staging);
            throw new \RuntimeException('local_acquire_failed');
        }
    }

    /** Recursive copy, skipping symlinks (never follow a link out of the tree). */
    private static function rcopy(string $src, string $dst): void
    {
        if (is_link($src)) {
            return; // never copy/follow symlinks
        }
        if (is_dir($src)) {
            if (!is_dir($dst) && !@mkdir($dst, 0o750, true)) {
                throw new \RuntimeException('local_acquire_failed');
            }
            foreach (scandir($src) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                self::rcopy($src . '/' . $entry, $dst . '/' . $entry);
            }

            return;
        }
        if (is_file($src)) {
            if (is_link($dst)) {
                return;
            }
            if (!@copy($src, $dst)) {
                throw new \RuntimeException('local_acquire_failed');
            }
        }
    }

    /** Recursive delete (reuses the extractor's helper when available). */
    private static function rrmdir(string $dir): void
    {
        if (class_exists('PluginGitpluginsExtractor')) {
            PluginGitpluginsExtractor::rrmdir($dir);

            return;
        }
        if (!is_dir($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                $p = $dir . '/' . $e;
                is_dir($p) && !is_link($p) ? self::rrmdir($p) : @unlink($p);
            }
        }
        @rmdir($dir);
    }
}
