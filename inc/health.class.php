<?php
/**
 * Git Plugin Installer — post-install health gate (Phase 5).
 *
 * "Activated" is necessary but not sufficient: a plugin can register and activate
 * yet be misconfigured or missing prerequisites (assetreport/glpi-sre-hub expose
 * plugin_<key>_check_prerequisites() / plugin_<key>_check_config()). After R3
 * verify we additionally call those hooks when present and map the result to a
 * health verdict (ok|warn|fail|unknown) stored on the installs row and shown on
 * status. The verdict MAPPING is PURE (unit-tested); evaluate() is the live-box
 * wrapper that invokes the target's global check functions.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsHealth
{
    /**
     * PURE: map the two optional check results to a health verdict. Each result
     * is true (passed), false (failed), or null (the check does not exist).
     *  - any explicit false → 'fail'
     *  - both null (no self-checks) → 'unknown'
     *  - otherwise → 'ok'
     * Unit-tested; no I/O.
     */
    public static function classify(?bool $prerequisites, ?bool $config): string
    {
        if ($prerequisites === false || $config === false) {
            return 'fail';
        }
        if ($prerequisites === null && $config === null) {
            return 'unknown';
        }

        return 'ok';
    }

    /**
     * Invoke the target plugin's own check hooks (live-box) and return a verdict.
     * A check that is absent contributes null; a check that throws is treated as
     * a failure of that check (defensive — a broken check is not "healthy").
     *
     * @return array{health:string,detail:string}
     */
    public static function evaluate(string $key): array
    {
        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            return ['health' => 'unknown', 'detail' => ''];
        }
        $prereq = self::callCheck('plugin_' . $key . '_check_prerequisites');
        $config = self::callCheck('plugin_' . $key . '_check_config');

        $health  = self::classify($prereq, $config);
        $details = [];
        if ($prereq === false) {
            $details[] = 'prerequisites';
        }
        if ($config === false) {
            $details[] = 'configuration';
        }
        $detail = $details === [] ? '' : ('failed: ' . implode(', ', $details));

        return ['health' => $health, 'detail' => mb_substr($detail, 0, 255)];
    }

    /**
     * Call a plugin check function if it exists. Returns null when absent, else
     * the boolean-coerced return (a thrown check counts as false, not healthy).
     */
    private static function callCheck(string $fn): ?bool
    {
        if (!function_exists($fn)) {
            return null;
        }
        try {
            // GLPI check hooks accept a $verbose flag; pass false so nothing is
            // echoed into our install run.
            return (bool) $fn(false);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
