<?php
/**
 * Git Plugin Installer — sanitised archive extraction + atomic placement.
 *
 * Archive extraction is the classic zip-slip / path-traversal surface. Every
 * entry is sanitised (sanitiseEntryPath — pure, unit-tested) BEFORE it is
 * written; extraction happens into a FRESH temp dir, never over the live tree;
 * the single top-level dir is validated and renamed to the plugin key; the
 * staged dir is then moved into plugins/ atomically with backup-and-restore.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsExtractor
{
    /**
     * Sanitise one archive entry's path to a safe RELATIVE path, or null if it
     * must be rejected. Rejects absolute paths, any '..' segment, NUL bytes,
     * backslashes, and drive-letter/Windows roots. Pure + heavily unit-tested.
     */
    public static function sanitiseEntryPath(string $entry): ?string
    {
        if ($entry === '' || str_contains($entry, "\0")) {
            return null;
        }
        // Normalise separators; reject backslashes outright (Windows traversal).
        if (str_contains($entry, '\\')) {
            return null;
        }
        // Absolute path or Windows drive root.
        if ($entry[0] === '/' || preg_match('#^[A-Za-z]:#', $entry)) {
            return null;
        }
        $segments = explode('/', $entry);
        $clean    = [];
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.') {
                continue; // collapse empty / current-dir segments
            }
            if ($seg === '..') {
                return null; // ANY parent traversal → reject the whole entry
            }
            $clean[] = $seg;
        }
        if ($clean === []) {
            return null;
        }

        return implode('/', $clean);
    }

    /**
     * The single top-level directory shared by all sanitised entries, or null if
     * the archive has multiple roots or none (a valid plugin tarball has exactly
     * one top dir, e.g. "repo-1.2.3/").
     *
     * @param string[] $safePaths already-sanitised relative paths
     */
    public static function singleTopDir(array $safePaths): ?string
    {
        $top = null;
        foreach ($safePaths as $p) {
            $first = explode('/', $p, 2)[0];
            if ($first === '') {
                return null;
            }
            if ($top === null) {
                $top = $first;
            } elseif ($top !== $first) {
                return null; // more than one root
            }
        }

        return $top;
    }

    /**
     * Validate the staged layout: a single top dir must exist and, once renamed
     * to $expectedKey, must contain a setup.php (a real GLPI plugin). Pure check
     * over a path-list + a setup.php presence flag (the FS check is the caller's).
     *
     * @param string[] $safePaths
     */
    public static function layoutHasSetup(array $safePaths, string $top): bool
    {
        $needle = $top . '/setup.php';
        foreach ($safePaths as $p) {
            if ($p === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract $archive into a fresh staging dir, sanitising every entry, then
     * rename the single top dir to $key and verify setup.php. Returns the staged
     * plugin dir path ("<staging>/<key>"). Live-box only (uses the bundled
     * UnifiedArchive); throws \RuntimeException('extract_failed') on any problem.
     */
    public static function extractTo(string $archive, string $key): string
    {
        $tmpBase = defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir();
        $staging = $tmpBase . '/gitplugins_x_' . bin2hex(random_bytes(8));
        if (!@mkdir($staging, 0o750, true)) {
            throw new \RuntimeException('extract_failed');
        }

        try {
            if (!class_exists('\\wapmorgan\\UnifiedArchive\\UnifiedArchive')) {
                throw new \RuntimeException('extract_failed');
            }
            $arc = \wapmorgan\UnifiedArchive\UnifiedArchive::open($archive);
            if ($arc === null) {
                throw new \RuntimeException('extract_failed');
            }

            $safePaths = [];
            foreach ($arc->getFileNames() as $name) {
                $safe = self::sanitiseEntryPath((string) $name);
                if ($safe === null) {
                    // A poisoned entry → abort the whole archive (fail closed).
                    throw new \RuntimeException('extract_failed');
                }
                $safePaths[] = $safe;
            }

            $top = self::singleTopDir($safePaths);
            if ($top === null || !self::layoutHasSetup($safePaths, $top)) {
                throw new \RuntimeException('extract_failed');
            }

            // Extract entry-by-entry to our sanitised relative paths (never trust
            // the library to honour our sanitisation).
            foreach ($arc->getFileNames() as $name) {
                $safe = self::sanitiseEntryPath((string) $name);
                if ($safe === null) {
                    continue;
                }
                $absTarget = $staging . '/' . $safe;
                // Skip pure directory entries (created implicitly below).
                if (str_ends_with((string) $name, '/')) {
                    @mkdir($absTarget, 0o750, true);
                    continue;
                }
                @mkdir(dirname($absTarget), 0o750, true);
                $content = $arc->getFileContent((string) $name);
                if ($content === false) {
                    throw new \RuntimeException('extract_failed');
                }
                if (file_put_contents($absTarget, $content) === false) {
                    throw new \RuntimeException('extract_failed');
                }
            }

            // Rename the top dir → plugin key; verify setup.php on disk.
            $from   = $staging . '/' . $top;
            $staged = $staging . '/' . $key;
            if ($top !== $key && !@rename($from, $staged)) {
                throw new \RuntimeException('extract_failed');
            }
            $staged = ($top === $key) ? $from : $staged;
            if (!is_file($staged . '/setup.php')) {
                throw new \RuntimeException('extract_failed');
            }
            // Ensure no VCS metadata slipped in (core's update guard rejects it).
            foreach (['.git', '.hg', '.svn'] as $vcs) {
                if (is_dir($staged . '/' . $vcs)) {
                    self::rrmdir($staged . '/' . $vcs);
                }
            }

            return $staged;
        } catch (\Throwable $e) {
            self::rrmdir($staging);
            throw new \RuntimeException('extract_failed');
        }
    }

    /**
     * Move a staged plugin dir into plugins/<key> atomically: back up an existing
     * dir to <key>.bak.<ts>, move the staged dir in, restore on failure, purge
     * the backup on success. Live-box only; throws on failure.
     */
    public static function placeAtomically(string $staged, string $pluginsBase, string $key): void
    {
        // Key must be a plain plugin dir name — never a path.
        if ($key === '' || !preg_match('/^[a-z0-9_]+$/', $key)) {
            throw new \RuntimeException('place_failed');
        }
        $target = rtrim($pluginsBase, '/') . '/' . $key;
        $backup = null;

        if (is_dir($target)) {
            $backup = $target . '.bak.' . date('YmdHis');
            if (!@rename($target, $backup)) {
                throw new \RuntimeException('place_failed');
            }
        }

        if (!@rename($staged, $target)) {
            // Restore the backup before bailing out.
            if ($backup !== null) {
                @rename($backup, $target);
            }
            throw new \RuntimeException('place_failed');
        }

        // Match GLPI's web-user ownership (no root-owned files — lesson #16).
        if (function_exists('posix_getpwnam')) {
            $pw = @posix_getpwnam('apache');
            if (is_array($pw)) {
                @chown($target, (int) $pw['uid']);
                @chgrp($target, (int) $pw['gid']);
            }
        }

        if ($backup !== null) {
            self::rrmdir($backup); // success → drop the backup
        }
    }

    /** Recursive rmdir (best-effort cleanup of temp/backup dirs). */
    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);

            return;
        }
        $items = @scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
