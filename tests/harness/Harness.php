<?php
/**
 * Runs a scenario in a fresh PHP process against the GLPI stand-ins
 * (tests/harness/glpi_stubs.php) and returns the JSON it prints. A separate
 * process keeps those stubs, the plugin's setup.php constants and its front
 * scripts out of the in-process pure suite.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class GitpluginsHarness
{
    /**
     * @param string               $php scenario body (PHP code, without the opening tag)
     * @param array<string,string> $env extra environment for the child
     * @return array<string,mixed>
     */
    public static function run(string $php, array $env = []): array
    {
        $file = tempnam(sys_get_temp_dir(), 'gp_harness_');
        if ($file === false) {
            throw new \RuntimeException('harness: tempnam failed');
        }
        file_put_contents($file, "<?php\ndeclare(strict_types=1);\n" . $php);
        try {
            $cmd = [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/run.php', $file];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + getenv());
            if (!is_resource($proc)) {
                throw new \RuntimeException('harness: proc_open failed');
            }
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        } finally {
            @unlink($file);
        }
        $lines = preg_split('/\R/', trim($out)) ?: [];
        $data  = json_decode((string) end($lines), true);
        if (!is_array($data)) {
            throw new \RuntimeException("harness: no JSON result.\nstdout: {$out}\nstderr: {$err}");
        }

        return $data;
    }
}
