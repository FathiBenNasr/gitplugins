<?php
/**
 * Git Plugin Installer — post-extract build step for SOURCE installs (R5).
 *
 * A branch/tag/SHA *source tarball* (unlike a prebuilt `release` .tgz) arrives
 * WITHOUT vendor/ or node_modules/, so a plugin that ships composer.json /
 * package.json is broken on arrival. When opted in per source, run the declared
 * build in the staged dir BEFORE it is placed live. This executes third-party
 * build scripts — same trust boundary as the plugin's own install() — so it is
 * OFF by default, surfaced on the confirm screen, and only ever runs in the
 * CronTask context (never a web request). The needs-build decision is PURE
 * (unit-tested); the exec itself is capability-probed and time-capped.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsBuilder
{
    /**
     * PURE: does the staged tree need a composer build? Only when opted in, a
     * composer.json is present, AND vendor/ is absent (a tarball that already
     * bundled vendor/, or a release, needs nothing). Filesystem facts are passed
     * in so this is table-testable.
     */
    public static function needsComposerBuild(bool $optIn, bool $hasComposerJson, bool $hasVendor): bool
    {
        return $optIn && $hasComposerJson && !$hasVendor;
    }

    /**
     * PURE: does the staged tree need an npm build? Only when opted in, a
     * package.json declares a build script, AND node_modules/ is absent.
     */
    public static function needsNpmBuild(bool $optIn, bool $hasPackageJson, bool $hasBuildScript, bool $hasNodeModules): bool
    {
        return $optIn && $hasPackageJson && $hasBuildScript && !$hasNodeModules;
    }

    /**
     * Run the opt-in build in a staged dir (live-box: exec + FS). Best-effort:
     * a failed or unavailable builder logs a warning and returns false, leaving
     * the caller to decide (the `release` policy remains the robust path). Never
     * throws — a build failure must not abort with a half-swapped tree.
     *
     * @return array{composer:?bool,npm:?bool} ran-and-succeeded per builder
     *         (null = not needed / not attempted)
     */
    public static function run(string $dir, bool $optIn, int $timeoutSeconds): array
    {
        $result = ['composer' => null, 'npm' => null];
        if (!$optIn || !is_dir($dir)) {
            return $result;
        }
        $timeoutSeconds = max(30, min(1800, $timeoutSeconds));

        $hasVendor = is_dir($dir . '/vendor');
        if (self::needsComposerBuild(true, is_file($dir . '/composer.json'), $hasVendor)
            && self::binaryAvailable('composer')) {
            $result['composer'] = self::exec(
                ['composer', 'install', '--no-dev', '--no-interaction', '--no-progress', '--prefer-dist'],
                $dir,
                $timeoutSeconds
            );
        }

        $pkg = self::readJson($dir . '/package.json');
        $hasBuildScript = is_array($pkg) && isset($pkg['scripts']['build']);
        if (self::needsNpmBuild(true, $pkg !== null, $hasBuildScript, is_dir($dir . '/node_modules'))
            && self::binaryAvailable('npm')) {
            $ok = self::exec(['npm', 'ci', '--no-audit', '--no-fund'], $dir, $timeoutSeconds);
            if ($ok) {
                $ok = self::exec(['npm', 'run', 'build'], $dir, $timeoutSeconds);
            }
            $result['npm'] = $ok;
        }

        return $result;
    }

    /** Is a build binary on PATH? Capability probe (best-effort, no throw). */
    public static function binaryAvailable(string $bin): bool
    {
        if (!preg_match('/^[a-z0-9_-]+$/i', $bin) || !function_exists('proc_open')) {
            return false;
        }
        $ok = self::exec(['command', '-v', $bin], null, 10);

        return $ok;
    }

    /**
     * Run a command as an argv array (never a shell string → no injection),
     * cwd-scoped, hard-timed-out. Returns true on exit code 0. Best-effort.
     */
    private static function exec(array $argv, ?string $cwd, int $timeout): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $cmd = implode(' ', array_map('escapeshellarg', $argv));
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        // A minimal, sanitised environment; keep PATH + HOME for the toolchain,
        // strip everything else (no secrets leak into build scripts).
        $env = [
            'PATH'                => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME'                => $cwd ?? sys_get_temp_dir(),
            'COMPOSER_NO_INTERACTION' => '1',
            'CI'                  => '1',
        ];
        $proc = @proc_open($cmd, $desc, $pipes, $cwd && is_dir($cwd) ? $cwd : null, $env);
        if (!is_resource($proc)) {
            return false;
        }
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                stream_set_blocking($p, false);
            }
        }
        $deadline = time() + $timeout;
        do {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                break;
            }
            usleep(100000);
        } while (time() < $deadline);

        $status = proc_get_status($proc);
        if ($status['running']) {
            @proc_terminate($proc, 9);
            foreach ($pipes as $p) {
                if (is_resource($p)) {
                    @fclose($p);
                }
            }
            @proc_close($proc);

            return false;
        }
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                @fclose($p);
            }
        }
        $code = proc_close($proc);

        return $code === 0;
    }

    /** Read + decode a JSON file, or null. */
    private static function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}
