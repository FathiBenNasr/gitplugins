<?php
/**
 * Git Plugin Installer — neutralised pre-update backups + rollback.
 *
 * SECURITY (owner directive, 2026-07-01): a backup of a plugin is a copy of
 * third-party code that may contain the very vulnerabilities we are updating
 * away from. If it lingers as loose `.php` files inside the web tree, an
 * attacker who reaches it can execute it and compromise the whole GLPI install.
 * So a backup is **neutralised**:
 *   1. RELOCATED out of the web tree entirely — into GLPI_VAR_DIR
 *      (`/var/lib/glpi/...`), which is not under the `public/` docroot and is
 *      never autoloaded as a plugin.
 *   2. PACKED into a single inert `.zip` — no loose executable file remains on
 *      disk at rest.
 *   3. LOCKED DOWN — the archive is chmod 0600 and the backups dir 0700,
 *      owned by the web user.
 * The loose old tree only ever exists momentarily under a dot-prefixed,
 * random, non-plugin-key name during the atomic swap, then becomes the zip.
 *
 * PharData is unusable here (phar.readonly=1 in production php.ini), so we use
 * ext-zip (present) for a reliable, dependency-free inert archive.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsBackup
{
    /**
     * Absolute backups directory, OUTSIDE the web tree. Created 0700 (web-user
     * owned) on first use. Prefers GLPI_VAR_DIR (`/var/lib/glpi`) — not under the
     * public docroot — falling back to the system temp dir only if unset.
     */
    public static function backupDir(): string
    {
        $base = defined('GLPI_VAR_DIR') ? GLPI_VAR_DIR
            : (defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir());
        $dir = rtrim($base, '/') . '/_gitplugins/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0o700, true);
        }
        @chmod($dir, 0o700);
        self::chownWeb($dir);

        return $dir;
    }

    /**
     * Build a safe, collision-resistant backup filename. PURE — no time/random
     * source of its own (caller passes $stamp + $rand) so it is unit-testable.
     * Every dynamic segment is reduced to [a-z0-9._-]; the plugin key is already
     * validated upstream but is re-sanitised here (defence in depth) so this can
     * never emit a path separator.
     */
    public static function backupFilename(string $key, string $version, string $stamp, string $rand): string
    {
        $k = self::sanitiseLabel($key !== '' ? $key : 'plugin');
        $v = self::sanitiseLabel($version !== '' ? $version : 'unknown');
        $s = self::sanitiseLabel($stamp !== '' ? $stamp : '0');
        $r = self::sanitiseLabel($rand !== '' ? $rand : '0');

        return "{$k}-{$v}-{$s}-{$r}.zip";
    }

    /** Reduce an arbitrary label to a filename-safe token (no path separators). */
    public static function sanitiseLabel(string $s): string
    {
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9._-]+/', '_', $s) ?? '';
        // Collapse dot runs so no ".." survives (defence-in-depth against any
        // traversal token leaking into a backup filename), then trim edges.
        $s = preg_replace('/\.{2,}/', '.', $s) ?? '';
        $s = trim($s, '._-');

        return $s === '' ? 'x' : mb_substr($s, 0, 60);
    }

    /**
     * Which carry-over dirs (e.g. vendor/, node_modules/) must be preserved from
     * the OLD tree into the NEW one: present in old, ABSENT in the new release
     * (a release that ships its own vendor/ wins). PURE — decides over two dir
     * lists, no filesystem access. Unit-tested.
     *
     * @param string[] $carry   configured carry-over dir names
     * @param string[] $oldDirs top-level dir names present in the old tree
     * @param string[] $newDirs top-level dir names present in the new tree
     * @return string[] dir names to carry over
     */
    public static function carryOverList(array $carry, array $oldDirs, array $newDirs): array
    {
        $old = array_flip($oldDirs);
        $new = array_flip($newDirs);
        $out = [];
        foreach ($carry as $dir) {
            $dir = (string) $dir;
            if ($dir === '' || str_contains($dir, '/') || str_contains($dir, '\\')) {
                continue; // only plain top-level dir names
            }
            if (isset($old[$dir]) && !isset($new[$dir])) {
                $out[] = $dir;
            }
        }

        return $out;
    }

    /**
     * Pack a (loose, on-disk) plugin tree into a neutralised inert .zip in the
     * out-of-web backups dir and return the archive path — or null on any
     * failure. Does NOT delete $looseDir (the caller owns its lifetime, e.g. to
     * still carry over vendor/ from it before removing it). Entries are stored
     * under a "<key>/" prefix so a restore recreates plugins/<key> exactly.
     * Symlinks are skipped (never follow a link out of the tree).
     */
    public static function zipDir(string $looseDir, string $key, string $version): ?string
    {
        if (!is_dir($looseDir) || !class_exists('ZipArchive')) {
            return null;
        }
        // random suffix without Date/Math: derived from a CSPRNG.
        $stamp = self::sanitiseLabel((string) @filemtime($looseDir));
        $rand  = bin2hex(random_bytes(6));
        $path  = self::backupDir() . '/' . self::backupFilename($key, $version, $stamp, $rand);

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            return null;
        }
        try {
            $zip->addEmptyDir($key);
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($looseDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $item) {
                /** @var \SplFileInfo $item */
                if ($item->isLink()) {
                    continue; // do not follow symlinks into the archive
                }
                $rel = substr($item->getPathname(), strlen($looseDir) + 1);
                $rel = str_replace('\\', '/', $rel);
                $entry = $key . '/' . $rel;
                if ($item->isDir()) {
                    $zip->addEmptyDir($entry);
                } elseif ($item->isFile()) {
                    $zip->addFile($item->getPathname(), $entry);
                }
            }
            $zip->close();
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($path);

            return null;
        }
        @chmod($path, 0o600);
        self::chownWeb($path);

        return $path;
    }

    /**
     * Restore a neutralised backup zip back to plugins/<key>, replacing whatever
     * is there now. Returns true on success. Used by the post-install verify
     * self-heal (R3) to roll a failed update back to the previously working tree.
     */
    public static function restore(string $zipPath, string $pluginsBase, string $key): bool
    {
        if (!is_file($zipPath) || !class_exists('ZipArchive')
            || $key === '' || !preg_match('/^[a-z0-9_]+$/', $key)) {
            return false;
        }
        $target = rtrim($pluginsBase, '/') . '/' . $key;

        // Extract into a fresh temp dir first, then atomically swap into place —
        // so a half-extracted tree never becomes the live plugin.
        $tmpBase = defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir();
        $tmp = $tmpBase . '/gitplugins_restore_' . bin2hex(random_bytes(8));
        if (!@mkdir($tmp, 0o750, true)) {
            return false;
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            PluginGitpluginsExtractor::rrmdir($tmp);

            return false;
        }
        $ok = $zip->extractTo($tmp);
        $zip->close();
        $restored = $tmp . '/' . $key;
        if (!$ok || !is_dir($restored) || !is_file($restored . '/setup.php')) {
            PluginGitpluginsExtractor::rrmdir($tmp);

            return false;
        }

        if (is_dir($target)) {
            PluginGitpluginsExtractor::rrmdir($target);
        }
        if (!@rename($restored, $target)) {
            // cross-device fallback: recursive copy.
            self::rcopy($restored, $target);
        }
        PluginGitpluginsExtractor::rrmdir($tmp);
        self::chownWebRecursive($target);

        return is_file($target . '/setup.php');
    }

    /** Best-effort recursive copy (cross-device rename fallback). */
    private static function rcopy(string $src, string $dst): void
    {
        @mkdir($dst, 0o750, true);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            $rel = substr($item->getPathname(), strlen($src) + 1);
            $to  = $dst . '/' . $rel;
            if ($item->isLink()) {
                continue;
            }
            if ($item->isDir()) {
                @mkdir($to, 0o750, true);
            } elseif ($item->isFile()) {
                @copy($item->getPathname(), $to);
            }
        }
    }

    /** chown a single path to the web user (mirrors Extractor placement). */
    public static function chownWeb(string $path): void
    {
        if (!function_exists('posix_getpwnam')) {
            return;
        }
        $pw = @posix_getpwnam('apache');
        if (is_array($pw)) {
            @chown($path, (int) $pw['uid']);
            @chgrp($path, (int) $pw['gid']);
        }
    }

    /** chown a whole tree to the web user (best effort). */
    private static function chownWebRecursive(string $path): void
    {
        self::chownWeb($path);
        if (!is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            /** @var \SplFileInfo $item */
            self::chownWeb($item->getPathname());
        }
    }
}
