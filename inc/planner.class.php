<?php
/**
 * Git Plugin Installer — bulk-update dry-run planner (Phase 9).
 *
 * "Update all managed plugins, preview first." The dry-run assembles, per managed
 * plugin, a NON-MUTATING plan row: installed → available version, the resolved
 * action (install/update/skip/blocked_downgrade), whether it crosses a version
 * (migration likely), the host preflight (R6) verdict, and the known-issue (F7)
 * count. Rendered as a table; the admin ticks rows and applies, which only queues
 * pending_action='update' for the cron worker to run through the Phase 0 pipeline.
 *
 * buildPlanRow() is PURE (unit-tested from mocked inputs); plan() is the live
 * gather. Deliberately NETWORK-FREE: it reads the availability the update-check
 * cron already stored (installs row), never re-fetching in the web request
 * (#7/#11). The per-plugin confirm screen still does the live changelog fetch.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsPlanner
{
    /**
     * PURE: assemble one plan row from already-resolved inputs.
     *
     * @param array{ok:bool,blockers:string[],warnings:string[]} $preflight
     * @return array{plugin_key:string,installed:string,available:string,action:string,selectable:bool,needs_migration:bool,preflight_ok:bool,blockers:string[],warnings:string[],known_issues:int,blocked:bool}
     */
    public static function buildPlanRow(
        string $key,
        string $installed,
        string $available,
        array $preflight,
        int $knownIssuesCount
    ): array {
        $installed = trim($installed);
        $available = trim($available);
        $action    = PluginGitpluginsInstaller::decideAction($installed, $available, false);

        $preflightOk = (bool) ($preflight['ok'] ?? true);
        // A row is applicable (tickable) only when there is genuinely work to do
        // AND the host can run it (preflight passes). A blocked downgrade or a
        // preflight failure is shown but not selectable.
        $selectable = in_array($action, ['install', 'update'], true) && $preflightOk;

        return [
            'plugin_key'      => $key,
            'installed'       => $installed,
            'available'       => $available,
            'action'          => $action,
            'selectable'      => $selectable,
            'needs_migration' => $installed !== '' && $available !== '' && PluginGitpluginsVersion::isNewer($available, $installed),
            'preflight_ok'    => $preflightOk,
            'blockers'        => array_values(array_map('strval', $preflight['blockers'] ?? [])),
            'warnings'        => array_values(array_map('strval', $preflight['warnings'] ?? [])),
            'known_issues'    => max(0, $knownIssuesCount),
            'blocked'         => $action === 'blocked_downgrade' || !$preflightOk,
        ];
    }

    /**
     * PURE: the plugin_keys in a plan that are selectable for a bulk apply.
     *
     * @param array<int,array<string,mixed>> $planRows
     * @return string[]
     */
    public static function selectableKeys(array $planRows): array
    {
        $out = [];
        foreach ($planRows as $r) {
            if (!empty($r['selectable'])) {
                $out[] = (string) ($r['plugin_key'] ?? '');
            }
        }

        return array_values(array_filter($out, static fn ($k) => $k !== ''));
    }

    /**
     * Live: build the full dry-run plan over every active managed source, using
     * the availability the cron already stored (no network here). Reads our own
     * tables + the host environment + core glpi_plugins (READ-only).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function plan(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        // Stored install-state per plugin_key (installed + available, cron-refreshed).
        $installs = [];
        foreach ($DB->request(['FROM' => 'glpi_plugin_gitplugins_installs']) as $i) {
            $installs[(string) $i['plugin_key']] = $i;
        }

        // Known-issues registry + active peers, loaded once (pure eval per row).
        $knownIssues = PluginGitpluginsKnownissues::load();
        $peers       = PluginGitpluginsKnownissues::installedPeers();

        $rows = [];
        foreach (PluginGitpluginsSource::activeRows(true) as $src) {
            $key = (string) ($src['plugin_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $i         = $installs[$key] ?? [];
            $installed = (string) ($i['installed_version'] ?? PluginGitpluginsInstaller::installedVersion($key));
            $available = (string) ($i['available_version'] ?? '');

            // R6 preflight against THIS host (no network — versions + extensions).
            $preflight = PluginGitpluginsPreflight::checkEnvironment(
                PluginGitpluginsPreflight::requirementsFor([], $key)
            );
            $ki = PluginGitpluginsKnownissues::evaluate($knownIssues, $key, $installed, $peers);

            $rows[] = self::buildPlanRow($key, $installed, $available, $preflight, count($ki));
        }

        return $rows;
    }

    /**
     * Live: queue pending_action='update' for the given plugin_keys, restricted to
     * rows that are genuinely selectable in the current plan (never blindly trust
     * the POSTed key list). Returns the number of rows queued. Writes ONLY our
     * installs table.
     *
     * @param string[] $requestedKeys
     */
    public static function queueBulk(array $requestedKeys): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $selectable = array_flip(self::selectableKeys(self::plan()));
        $queued     = 0;
        foreach (array_unique($requestedKeys) as $key) {
            $key = (string) $key;
            if ($key === '' || !isset($selectable[$key])) {
                continue;
            }
            $DB->update('glpi_plugin_gitplugins_installs', [
                'pending_action' => 'update',
                'last_result'    => 'pending',
                'last_error'     => null,
            ], ['plugin_key' => $key]);
            PluginGitpluginsLog::record(null, 'enqueue', 'ok', 'bulk update ' . $key);
            $queued++;
        }

        return $queued;
    }
}
