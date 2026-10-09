<?php
/**
 * Child-process entry point of the test harness: load the GLPI stand-ins, then
 * run one scenario file. The scenario prints a single JSON object on its last
 * line, which GitpluginsHarness::run() decodes for the calling test.
 *
 *   php tests/harness/run.php <scenario.php>
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

require __DIR__ . '/glpi_stubs.php';

define('GP_PLUGIN_DIR', dirname(__DIR__, 2));

// The plugin's own classes, loaded from inc/ exactly as GLPI's plugin
// autoloader does (PluginGitpluginsFoo → inc/foo.class.php). A scenario may
// declare a class first to replace one collaborator (e.g. the network-bound
// installer) — the real file is then never loaded.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'PluginGitplugins')) {
        $file = GP_PLUGIN_DIR . '/inc/' . strtolower(substr($class, strlen('PluginGitplugins'))) . '.class.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

/**
 * Run a plugin web script as GLPI's front controller would, and report how it
 * ended: 'redirect' (with the URL), 'denied', 'error' (any other throwable,
 * e.g. it ran past the guard into code the stubs do not provide) or 'done'.
 *
 * @return array{outcome:string,detail:string,output:string}
 */
function gp_run_script(string $relPath): array
{
    ob_start();
    try {
        (static function (string $file): void {
            require $file;
        })(GP_PLUGIN_DIR . '/' . $relPath);
        $outcome = ['done', ''];
    } catch (HarnessRedirect $e) {
        $outcome = ['redirect', $e->getMessage()];
    } catch (\Glpi\Exception\Http\AccessDeniedHttpException $e) {
        $outcome = ['denied', $e->getMessage()];
    } catch (\Throwable $e) {
        $outcome = ['error', get_class($e) . ': ' . $e->getMessage()];
    }
    $out = (string) ob_get_clean();

    return ['outcome' => $outcome[0], 'detail' => $outcome[1], 'output' => $out];
}

/** Write journal of the fake $DB, minus reads. */
function gp_writes(): array
{
    return $GLOBALS['DB']->log;
}

require $argv[1];
