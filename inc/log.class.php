<?php
/**
 * Git Plugin Installer — audit log writer (A09).
 *
 * Records every fetch/install/update to glpi_plugin_gitplugins_logs AND the GLPI
 * Event log. Messages are GENERIC — never a stack trace, never a credential,
 * never a token. Writes only to our own table (lesson #13).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsLog
{
    /**
     * Record one audited fetch/install/update to our log table and the GLPI
     * Event log. Messages are generic (no secrets); all fields are length-capped.
     *
     * @param 'ok'|'error' $result
     */
    public static function record(
        ?int $sourceId,
        string $action,
        string $result = 'ok',
        string $message = '',
        ?string $ref = null,
        ?string $sha = null
    ): void {
        /** @var DBmysql $DB */
        global $DB;

        if (!$DB->tableExists('glpi_plugin_gitplugins_logs')) {
            return;
        }
        $DB->insert('glpi_plugin_gitplugins_logs', [
            'plugin_gitplugins_sources_id' => $sourceId,
            'users_id'      => (int) (Session::getLoginUserID() ?: 0) ?: null,
            'action'        => mb_substr($action, 0, 64),
            'ref'           => $ref !== null ? mb_substr($ref, 0, 255) : null,
            'sha'           => $sha !== null ? mb_substr($sha, 0, 64) : null,
            'result'        => $result === 'error' ? 'error' : 'ok',
            'message'       => mb_substr($message, 0, 255),
            'date_creation' => date('Y-m-d H:i:s'),
        ]);

        if (class_exists('Event')) {
            Event::log(
                (int) ($sourceId ?? 0),
                'gitplugins',
                4,
                'plugin',
                sprintf('%s: %s (%s)', $action, $message, $result)
            );
        }
    }
}
