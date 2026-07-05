<?php
/**
 * Git Plugin Installer — locale compile (.po → .mo) on fetch (R7).
 *
 * GLPI loads compiled .mo catalogues; a managed third-party plugin that ships
 * only .po (or a stale .mo) silently falls back to English. On fetch, for each
 * locales/*.po whose .mo is missing or older, compile with msgfmt when the
 * binary is present; skip with a logged warning when it is absent. The stale
 * detection is PURE (unit-tested); the compile is capability-gated.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsLocales
{
    /**
     * PURE: does a .po need (re)compiling to .mo? True when the .mo is missing
     * ($moMtime === 0/null) or older than the .po. Mtimes are passed in so the
     * decision is table-testable with no filesystem.
     */
    public static function poNeedsCompile(int $poMtime, ?int $moMtime): bool
    {
        if ($poMtime <= 0) {
            return false; // no readable .po → nothing to do
        }
        if ($moMtime === null || $moMtime <= 0) {
            return true;  // .mo absent
        }

        return $poMtime > $moMtime; // .po newer than .mo → stale
    }

    /**
     * Compile every stale/missing .mo under $dir/locales (live-box: exec + FS).
     * Best-effort — returns the count compiled; when msgfmt is unavailable it
     * compiles nothing and returns 0 (the caller logs a single warning).
     */
    public static function compile(string $dir): int
    {
        $localesDir = $dir . '/locales';
        if (!is_dir($localesDir) || !self::msgfmtAvailable()) {
            return 0;
        }
        $compiled = 0;
        foreach (glob($localesDir . '/*.po') ?: [] as $po) {
            $mo = substr($po, 0, -3) . '.mo';
            $poMtime = (int) @filemtime($po);
            $moMtime = is_file($mo) ? (int) @filemtime($mo) : null;
            if (!self::poNeedsCompile($poMtime, $moMtime)) {
                continue;
            }
            if (self::runMsgfmt($po, $mo)) {
                @chmod($mo, 0644);
                $compiled++;
            }
        }

        return $compiled;
    }

    /** Is msgfmt on PATH? Capability probe (reuses Builder's exec if present). */
    public static function msgfmtAvailable(): bool
    {
        if (class_exists('PluginGitpluginsBuilder')) {
            return PluginGitpluginsBuilder::binaryAvailable('msgfmt');
        }

        return false;
    }

    /** msgfmt -c -o <mo> <po> as an argv array (no shell string). */
    private static function runMsgfmt(string $po, string $mo): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $cmd = implode(' ', array_map('escapeshellarg', ['msgfmt', '-c', '-o', $mo, $po]));
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                @fclose($p);
            }
        }

        return proc_close($proc) === 0;
    }
}
