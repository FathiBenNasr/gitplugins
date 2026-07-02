<?php
/**
 * Git Plugin Installer — environment / requirements preflight (R6).
 *
 * Before we fetch+install a managed plugin we check that the box can actually
 * run it: GLPI version in the plugin's declared [min,max], PHP >= declared min,
 * and every required PHP extension loaded. A missing extension otherwise yields
 * a half-installed plugin that silently deactivates and 404s (lesson: our suite
 * needs snmp/pcntl/gd/zip). The decision core is PURE (facts passed in) so it is
 * fully unit-tested; checkEnvironment() is the thin wrapper that reads the real
 * runtime. This same function backs the human-facing preflight report (F4/P4).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsPreflight
{
    /**
     * PURE decision: can this environment run the plugin? All runtime facts are
     * passed in (no globals, no phpversion()/extension_loaded()) so the whole
     * matrix is table-testable.
     *
     * @param array $req  normalised requirements:
     *                    glpi_min, glpi_max (''=unbounded), php_min (''=none),
     *                    extensions (string[])
     * @param string   $glpiVersion       running GLPI version
     * @param string   $phpVersion        running PHP version
     * @param string[] $loadedExtensions  lower-cased loaded PHP extension names
     * @return array{ok:bool,blockers:string[],warnings:string[]}
     */
    public static function check(array $req, string $glpiVersion, string $phpVersion, array $loadedExtensions): array
    {
        $blockers = [];
        $warnings = [];

        $glpi = self::verOf($glpiVersion);
        $php  = self::verOf($phpVersion);

        $glpiMin = self::verOf((string) ($req['glpi_min'] ?? ''));
        $glpiMax = self::verOf((string) ($req['glpi_max'] ?? ''));
        $phpMin  = self::verOf((string) ($req['php_min'] ?? ''));

        if ($glpiMin !== '' && $glpi !== '' && version_compare($glpi, $glpiMin, '<')) {
            $blockers[] = "GLPI {$glpi} < required minimum {$glpiMin}";
        }
        if ($glpiMax !== '' && $glpi !== '' && version_compare($glpi, $glpiMax, '>')) {
            // Over the declared max is a warning, not a hard stop: plugins often
            // run past their tested ceiling, and blocking would prevent adopting
            // a plugin on a newer GLPI the admin knows is fine.
            $warnings[] = "GLPI {$glpi} > tested maximum {$glpiMax}";
        }
        if ($phpMin !== '' && $php !== '' && version_compare($php, $phpMin, '<')) {
            $blockers[] = "PHP {$php} < required minimum {$phpMin}";
        }

        $loaded = [];
        foreach ($loadedExtensions as $e) {
            $e = strtolower(trim((string) $e));
            if ($e !== '') {
                $loaded[$e] = true;
            }
        }
        foreach (self::normaliseExtensions($req['extensions'] ?? []) as $ext) {
            if (!isset($loaded[$ext])) {
                $blockers[] = "missing PHP extension: {$ext}";
            }
        }

        return [
            'ok'       => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    /**
     * Read the real runtime facts and run check(). Live-box wrapper (globals +
     * extension_loaded); the decision itself stays in the pure check().
     *
     * @return array{ok:bool,blockers:string[],warnings:string[]}
     */
    public static function checkEnvironment(array $req): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $glpiVersion = '';
        if (defined('GLPI_VERSION')) {
            $glpiVersion = (string) GLPI_VERSION;
        } elseif (isset($CFG_GLPI['version'])) {
            $glpiVersion = (string) $CFG_GLPI['version'];
        }

        return self::check(
            $req,
            $glpiVersion,
            PHP_VERSION,
            array_map('strtolower', get_loaded_extensions())
        );
    }

    /**
     * Merge requirements declared in plugin.xml with a small built-in heuristic
     * map for our own suite (plugins that need an extension but don't declare it
     * in a machine-readable way). PURE. Returns the normalised requirement array
     * consumed by check().
     *
     * @param array  $manifestInfo output of Manifest::parseInfo (may lack req fields)
     * @param string $key           plugin key (drives the heuristic map)
     * @return array{glpi_min:string,glpi_max:string,php_min:string,extensions:string[]}
     */
    public static function requirementsFor(array $manifestInfo, string $key): array
    {
        $exts = self::normaliseExtensions($manifestInfo['extensions'] ?? []);

        // Built-in heuristics for our own plugins (best-effort; only adds, never
        // removes what the manifest declared).
        static $known = [
            'glpiinventory' => ['snmp', 'zip'],
            'assetreport'   => ['gd', 'zip'],
            'assetcve'      => ['zip'],
        ];
        foreach ($known[$key] ?? [] as $e) {
            $exts[$e] = $e;
        }

        return [
            'glpi_min'   => (string) ($manifestInfo['glpi_min'] ?? ''),
            'glpi_max'   => (string) ($manifestInfo['glpi_max'] ?? ''),
            'php_min'    => (string) ($manifestInfo['php_min'] ?? ''),
            'extensions' => array_values($exts),
        ];
    }

    /** Normalise an extensions list to a lower-cased, de-duped, safe-name set. */
    private static function normaliseExtensions($raw): array
    {
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $e) {
                $e = strtolower(trim((string) $e));
                if ($e !== '' && preg_match('/^[a-z0-9_]+$/', $e)) {
                    $out[$e] = $e;
                }
            }
        }

        return $out;
    }

    /** Sanitise a version string to a version_compare-safe token ('' if none). */
    private static function verOf(string $v): string
    {
        $v = trim($v);
        // Keep the leading dotted-numeric core (strip a leading 'v', trailing junk).
        if (preg_match('/\d+(\.\d+)*/', $v, $m)) {
            return $m[0];
        }

        return '';
    }
}
