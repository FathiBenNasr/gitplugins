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
    /** Audit table. */
    public const TABLE = 'glpi_plugin_gitplugins_logs';

    /**
     * Audit rows are kept one year. WHY: they name the acting user
     * (users_id); personal data needs a retention limit (RGPD, loi 2004-63),
     * and a year still covers any investigation of an install or a rollback.
     */
    public const RETENTION_DAYS = 365;

    /** PURE: the creation date before which audit rows are expired. */
    public static function purgeCutoff(int $now, int $days = self::RETENTION_DAYS): string
    {
        return date('Y-m-d H:i:s', $now - max(1, $days) * 86400);
    }

    /** Live: drop audit rows older than the retention period. Best-effort. */
    public static function purgeExpired(int $now): void
    {
        /** @var DBmysql $DB */
        global $DB;

        try {
            if ($DB->tableExists(self::TABLE)) {
                $DB->delete(self::TABLE, ['date_creation' => ['<', self::purgeCutoff($now)]]);
            }
        } catch (\Throwable $e) {
            // retention is housekeeping — never fail the cron over it
        }
    }

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

        // WHY: test the class actually called. The global `Event` alias does not
        // exist under GLPI 11/12, so the old check was always false and nothing
        // ever reached GLPI's own event log.
        if (class_exists(\Glpi\Event::class)) {
            \Glpi\Event::log(
                (int) ($sourceId ?? 0),
                'gitplugins',
                4,
                'plugin',
                sprintf('%s: %s (%s)', $action, $message, $result)
            );
        }
    }
}
