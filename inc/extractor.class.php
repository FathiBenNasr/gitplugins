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
    /** Max entries an archive may hold (a GLPI plugin is a few hundred to a few thousand). */
    public const MAX_ENTRIES = 20000;

    /** Max total UNCOMPRESSED bytes written by one extraction (128 MiB; configurable, see Config::getMaxExtractBytes). */
    public const MAX_TOTAL_BYTES = 134217728;

    /** Max UNCOMPRESSED bytes of ONE entry (32 MiB) — a plugin file is never larger. */
    public const MAX_ENTRY_BYTES = 33554432;

    /** Read/write granularity when streaming (bounds the memory of every copy loop). */
    private const CHUNK = 65536;

    /** Compressed-side granularity: inflate output per call is at most ~1032x this. */
    private const INFLATE_CHUNK = 8192;

    /**
     * PURE: does an extraction stay within budget? WHY: the download cap bounds
     * the COMPRESSED bytes only; a tar.gz bomb of a few MB expands to gigabytes
     * and fills GLPI_TMP_DIR / the plugins volume. Checked against the archive's
     * declared sizes BEFORE anything is written, and again against the bytes
     * actually produced while writing.
     */
    public static function withinBudget(
        int $entries,
        int $totalBytes,
        int $maxEntries = self::MAX_ENTRIES,
        int $maxBytes = self::MAX_TOTAL_BYTES
    ): bool {
        return $entries >= 0 && $totalBytes >= 0 && $entries <= $maxEntries && $totalBytes <= $maxBytes;
    }

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
     * PURE: does this 512-byte block look like a tar header (POSIX "ustar" magic)?
     */
    public static function isTarHeader(string $block): bool
    {
        return strlen($block) >= 512 && substr($block, 257, 5) === 'ustar';
    }

    /**
     * Largest tar byte stream a gzip may inflate to for a given budget: the
     * payload cap plus the per-entry tar framing (header + padding, doubled for
     * pax/long-name records) and the end-of-archive blocks.
     */
    public static function maxTarBytes(int $maxEntries, int $maxBytes): int
    {
        return $maxBytes + ($maxEntries * 2048) + 8192;
    }

    /**
     * Sniff the REAL format of $archive from its magic bytes (never from its
     * name — fetch() names every download ".tar.gz") and stage it under $workDir
     * under a name the driver understands. Only zip, tar and tar.gz are accepted;
     * a gzip is inflated to a plain tar on DISK through a bounded stream.
     *
     * WHY: the bundled library inflates a whole tar.gz into memory when it opens
     * it (a 190 KB tar.gz of zeros cost 217 MB at open), and it also opens rar,
     * 7z, iso, cab through external binaries. Deciding the format and bounding
     * the decompressed size BEFORE the library sees the file closes both. A
     * plain tar is read lazily by the driver, so memory stays flat.
     *
     * @return array{format:string,path:string} format is 'zip' or 'tar'
     * @throws \RuntimeException('extract_failed') on an unexpected format or a bomb
     */
    public static function stageArchive(string $archive, string $workDir, int $maxEntries, int $maxBytes): array
    {
        $in = @fopen($archive, 'rb');
        if ($in === false) {
            throw new \RuntimeException('extract_failed');
        }

        try {
            $head = (string) fread($in, 512);

            // zip: the driver streams entries; sizes are enforced while copying.
            if (strncmp($head, "PK\x03\x04", 4) === 0 || strncmp($head, "PK\x05\x06", 4) === 0) {
                return ['format' => 'zip', 'path' => self::copyBounded($in, $workDir . '/in.zip', self::maxTarBytes($maxEntries, $maxBytes))];
            }

            // plain tar.
            if (self::isTarHeader($head)) {
                return ['format' => 'tar', 'path' => self::copyBounded($in, $workDir . '/in.tar', self::maxTarBytes($maxEntries, $maxBytes))];
            }

            // gzip → must contain a tar; inflate to disk, counting real bytes.
            if (strncmp($head, "\x1f\x8b", 2) === 0 && function_exists('inflate_init')) {
                return ['format' => 'tar', 'path' => self::inflateTarBounded($in, $head, $workDir . '/in.tar', self::maxTarBytes($maxEntries, $maxBytes))];
            }
        } finally {
            fclose($in);
        }

        // rar, 7z, iso, cab, bz2, xz, … or garbage: not a format we expect.
        throw new \RuntimeException('extract_failed');
    }

    /** Copy an already-open stream (first 512 bytes consumed) to $dest, refusing past $cap bytes. */
    private static function copyBounded($in, string $dest, int $cap): string
    {
        rewind($in);
        $out = @fopen($dest, 'wb');
        if ($out === false) {
            throw new \RuntimeException('extract_failed');
        }
        $total = 0;
        try {
            while (!feof($in)) {
                $buf = fread($in, self::CHUNK);
                if ($buf === false) {
                    throw new \RuntimeException('extract_failed');
                }
                $total += strlen($buf);
                // WHY: refuse as soon as the real size passes the cap.
                if ($total > $cap || fwrite($out, $buf) === false) {
                    throw new \RuntimeException('extract_failed');
                }
            }
        } finally {
            fclose($out);
        }

        return $dest;
    }

    /**
     * Stream-inflate a gzip into $dest, never holding more than one small
     * chunk's output in memory and refusing as soon as the REAL decompressed
     * size passes $cap or the payload is not a tar.
     */
    private static function inflateTarBounded($in, string $head, string $dest, int $cap): string
    {
        rewind($in);
        $ctx = inflate_init(ZLIB_ENCODING_GZIP);
        $out = @fopen($dest, 'wb');
        if ($ctx === false || $out === false) {
            throw new \RuntimeException('extract_failed');
        }
        $total   = 0;
        $first   = '';
        $checked = false;
        try {
            $feed = static function (string $data) use (&$total, &$first, &$checked, $cap, $out): void {
                $total += strlen($data);
                // WHY: the declared gzip size is a trailer the sender controls;
                // only the bytes really produced are trusted.
                if ($total > $cap) {
                    throw new \RuntimeException('extract_failed');
                }
                if (!$checked) {
                    $first .= $data;
                    if (strlen($first) >= 512) {
                        // A gzip that is not a tar must not reach the tar driver.
                        if (!self::isTarHeader($first)) {
                            throw new \RuntimeException('extract_failed');
                        }
                        $checked = true;
                    }
                }
                if ($data !== '' && fwrite($out, $data) === false) {
                    throw new \RuntimeException('extract_failed');
                }
            };
            $ended = false;
            while (!feof($in) && !$ended) {
                $chunk = fread($in, self::INFLATE_CHUNK);
                if ($chunk === false) {
                    throw new \RuntimeException('extract_failed');
                }
                $data = @inflate_add($ctx, $chunk, ZLIB_NO_FLUSH);
                if ($data === false) {
                    throw new \RuntimeException('extract_failed');
                }
                $feed($data);
                $ended = inflate_get_status($ctx) === ZLIB_STREAM_END;
            }
            // Truncated stream (never reached its end) or not a tar.
            if (!$ended || !$checked) {
                throw new \RuntimeException('extract_failed');
            }
        } finally {
            fclose($out);
        }

        return $dest;
    }

    /**
     * Extract $archive into a fresh staging dir, sanitising every entry, then
     * rename the single top dir to $key and verify setup.php. Returns the staged
     * plugin dir path ("<staging>/<key>"). Live-box only (uses the bundled
     * UnifiedArchive); throws \RuntimeException('extract_failed') on any problem.
     * The two budget parameters default to MAX_ENTRIES / MAX_TOTAL_BYTES.
     */
    public static function extractTo(
        string $archive,
        string $key,
        int $maxEntries = self::MAX_ENTRIES,
        int $maxBytes = self::MAX_TOTAL_BYTES
    ): string
    {
        $tmpBase = defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir();
        $stem    = $tmpBase . '/gitplugins_x_' . bin2hex(random_bytes(8));
        $staging = $stem;
        $work    = $stem . '_src'; // the format-checked copy the library opens
        if (!@mkdir($staging, 0o750, true) || !@mkdir($work, 0o750, true)) {
            @rmdir($staging);
            throw new \RuntimeException('extract_failed');
        }

        try {
            if (!class_exists('\\wapmorgan\\UnifiedArchive\\UnifiedArchive')) {
                throw new \RuntimeException('extract_failed');
            }
            // WHY: format allowlist + bounded decompression happen before the
            // library parses anything (see stageArchive).
            $prepared = self::stageArchive($archive, $work, $maxEntries, $maxBytes);
            $arc = \wapmorgan\UnifiedArchive\UnifiedArchive::open($prepared['path']);
            if ($arc === null || !method_exists($arc, 'getFormat') || $arc->getFormat() !== $prepared['format']) {
                throw new \RuntimeException('extract_failed');
            }

            $names = $arc->getFileNames();
            // Bomb guard, before a single byte is written: entry count and the
            // sum of the sizes the archive itself declares.
            $declared = 0;
            foreach ($names as $name) {
                $data = method_exists($arc, 'getFileData') ? $arc->getFileData((string) $name) : null;
                if (is_object($data) && isset($data->uncompressedSize)) {
                    $declared += max(0, (int) $data->uncompressedSize);
                }
            }
            if (!self::withinBudget(count($names), $declared, $maxEntries, $maxBytes)) {
                throw new \RuntimeException('extract_failed');
            }

            $safePaths = [];
            foreach ($names as $name) {
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
            $written = 0;
            foreach ($names as $name) {
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
                // WHY: stream the entry in small chunks instead of loading it
                // whole — one huge entry can no longer exhaust the memory limit,
                // and the real size is bounded per entry and in total.
                $in = $arc->getFileStream((string) $name);
                if (!is_resource($in)) {
                    throw new \RuntimeException('extract_failed');
                }
                $out = @fopen($absTarget, 'wb');
                if ($out === false) {
                    fclose($in);
                    throw new \RuntimeException('extract_failed');
                }
                $entryBytes = 0;
                $entryCap   = min(self::MAX_ENTRY_BYTES, $maxBytes);
                try {
                    while (!feof($in)) {
                        $buf = fread($in, self::CHUNK);
                        if ($buf === false) {
                            throw new \RuntimeException('extract_failed');
                        }
                        $entryBytes += strlen($buf);
                        // Declared sizes can lie: bound what is really produced.
                        $written += strlen($buf);
                        if ($entryBytes > $entryCap
                            || !self::withinBudget(count($names), $written, $maxEntries, $maxBytes)) {
                            throw new \RuntimeException('extract_failed');
                        }
                        if ($buf !== '' && fwrite($out, $buf) === false) {
                            throw new \RuntimeException('extract_failed');
                        }
                    }
                } finally {
                    fclose($in);
                    fclose($out);
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
        } finally {
            self::rrmdir($work); // the staged copy of the archive is never kept
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

        // WHY: never replace through a symbolic link (see targetIsLink).
        if (self::targetIsLink($base, $key)) {
            throw new \RuntimeException('place_failed');
        }

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

    /**
     * PURE-ish (lstat only): is the plugins dir, or plugins/<key>, a symbolic link?
     * WHY: rename()/rrmdir() on a link act on the link or follow it to a tree
     * outside plugins/ (a dev checkout, another volume): an update would empty
     * that tree. We never act through a link — refuse instead.
     */
    public static function targetIsLink(string $pluginsBase, string $key): bool
    {
        $base = rtrim($pluginsBase, '/');

        return is_link($base) || is_link($base . '/' . $key);
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
        // WHY: is_dir() follows a link; removing "the directory" behind a link
        // would empty a tree we do not own. Drop the link itself, never its target.
        if (is_link($dir)) {
            @unlink($dir);

            return;
        }
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
