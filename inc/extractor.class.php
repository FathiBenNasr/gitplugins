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
     * Move a staged plugin dir into plugins/<key> atomically and produce a
     * NEUTRALISED backup of any prior install. Returns the backup archive path
     * (out of the web tree, inert .zip — see PluginGitpluginsBackup) so the
     * caller can roll back if the post-install verify fails; null on a fresh
     * install or if the backup could not be produced. Live-box only; throws on
     * placement failure.
     *
     * Flow: the old tree is moved aside to a dot-prefixed, random,
     * NON-plugin-key holding name (atomic, same filesystem — GLPI never
     * autoloads it), the staged tree is moved into place, then the holding tree
     * is (1) packed into an inert out-of-web zip, (2) mined for carry-over dirs
     * the new release lacks (vendor/, node_modules/), and (3) removed — so NO
     * loose executable copy of old code ever lingers under plugins/.
     *
     * @param string[] $carryOver runtime-built dir names to preserve across update
     */
    public static function placeAtomically(
        string $staged,
        string $pluginsBase,
        string $key,
        string $backupLabel = '',
        array $carryOver = ['vendor', 'node_modules']
    ): ?string {
        // Key must be a plain plugin dir name — never a path.
        if ($key === '' || !preg_match('/^[a-z0-9_]+$/', $key)) {
            throw new \RuntimeException('place_failed');
        }
        $base    = rtrim($pluginsBase, '/');
        $target  = $base . '/' . $key;
        $holding = null;

        if (is_dir($target)) {
            $holding = $base . '/.gitplugins_swap_' . bin2hex(random_bytes(8));
            if (!@rename($target, $holding)) {
                throw new \RuntimeException('place_failed');
            }
        }

        if (!@rename($staged, $target)) {
            // Restore the prior tree before bailing out.
            if ($holding !== null) {
                @rename($holding, $target);
            }
            throw new \RuntimeException('place_failed');
        }

        // Match GLPI's web-user ownership (no root-owned files — lesson #16).
        PluginGitpluginsBackup::chownWeb($target);

        if ($holding === null) {
            return null; // fresh install — nothing to back up
        }

        // 1) Neutralise the old tree into an inert, out-of-web zip FIRST (while it
        //    is still complete, so a rollback restores everything incl. vendor/).
        $backupZip = PluginGitpluginsBackup::zipDir($holding, $key, $backupLabel);

        // 2) Carry over runtime-built dirs the new release does not ship (so an
        //    optional one-click build — e.g. assetreport's mPDF vendor/ — is not
        //    wiped by an update).
        $carry = PluginGitpluginsBackup::carryOverList(
            $carryOver,
            self::topEntries($holding),
            self::topEntries($target)
        );
        foreach ($carry as $dir) {
            $from = $holding . '/' . $dir;
            $to   = $target . '/' . $dir;
            if (is_dir($from) && !file_exists($to)) {
                @rename($from, $to);
            }
        }

        // 3) Remove the loose old tree — only the neutralised zip remains.
        self::rrmdir($holding);

        return $backupZip; // null if zipping failed (rollback then unavailable)
    }

    /** Top-level entry names (dirs + files) of a directory. */
    public static function topEntries(string $dir): array
    {
        $out = [];
        foreach (@scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $out[] = $e;
        }

        return $out;
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
