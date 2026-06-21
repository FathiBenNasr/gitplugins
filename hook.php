<?php
/**
 * Git Plugin Installer — install / uninstall hooks.
 *
 * Idempotent schema creation (tableExists guards) + idempotent migrate
 * (fieldExists/isIndex ALTERs), cron registration, profile-right grant.
 * Writes ONLY to glpi_plugin_gitplugins_* (+ glpi_profilerights for our right).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

function plugin_gitplugins_install(): bool
{
    /** @var DBmysql $DB */
    global $DB;

    $charset   = 'utf8mb4';
    $collation = 'utf8mb4_unicode_ci';

    // ---- sources: a managed git/HTTPS repository + ref policy ----
    if (!$DB->tableExists('glpi_plugin_gitplugins_sources')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_gitplugins_sources` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`            VARCHAR(255) NOT NULL DEFAULT '',
                `url`             VARCHAR(255) NOT NULL DEFAULT '',
                `host`            VARCHAR(255) NOT NULL DEFAULT '',
                `provider`        ENUM('github','gitlab','gitea','forgejo','unknown') NOT NULL DEFAULT 'unknown',
                `plugin_key`      VARCHAR(64)  NOT NULL DEFAULT '',
                `ref_policy`      ENUM('track_branch','latest_tag','pin_tag','pin_sha','release') NOT NULL DEFAULT 'latest_tag',
                `ref`             VARCHAR(255) NULL DEFAULT NULL,
                `credential`      TEXT         NULL DEFAULT NULL,
                `entities_id`     INT UNSIGNED NOT NULL DEFAULT 0,
                `is_recursive`    TINYINT(1)   NOT NULL DEFAULT 0,
                `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
                `date_creation`   DATETIME     NULL DEFAULT NULL,
                `date_mod`        DATETIME     NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_plugin_key` (`plugin_key`),
                KEY `entities_id`    (`entities_id`),
                KEY `idx_active`     (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}"
        );
    }

    // ---- installs: per managed plugin, installed vs available version/SHA ----
    if (!$DB->tableExists('glpi_plugin_gitplugins_installs')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_gitplugins_installs` (
                `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_gitplugins_sources_id` INT UNSIGNED NOT NULL,
                `plugin_key`          VARCHAR(64)  NOT NULL DEFAULT '',
                `installed_version`   VARCHAR(64)  NULL DEFAULT NULL,
                `installed_sha`       VARCHAR(64)  NULL DEFAULT NULL,
                `available_version`   VARCHAR(64)  NULL DEFAULT NULL,
                `available_sha`       VARCHAR(64)  NULL DEFAULT NULL,
                `pending_action`      ENUM('none','install','update') NOT NULL DEFAULT 'none',
                `last_result`         ENUM('none','ok','error','pending') NOT NULL DEFAULT 'none',
                `last_error`          VARCHAR(255) NULL DEFAULT NULL,
                `last_check_at`       DATETIME     NULL DEFAULT NULL,
                `last_install_at`     DATETIME     NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_source` (`plugin_gitplugins_sources_id`),
                KEY `idx_plugin_key` (`plugin_key`),
                KEY `idx_pending`    (`pending_action`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}"
        );
    }

    // ---- logs: audit per fetch/install/update (generic messages, no secrets) ----
    if (!$DB->tableExists('glpi_plugin_gitplugins_logs')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_gitplugins_logs` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `plugin_gitplugins_sources_id` INT UNSIGNED NULL DEFAULT NULL,
                `users_id`        INT UNSIGNED NULL DEFAULT NULL,
                `action`          VARCHAR(64)  NOT NULL DEFAULT '',
                `ref`             VARCHAR(255) NULL DEFAULT NULL,
                `sha`             VARCHAR(64)  NULL DEFAULT NULL,
                `result`          ENUM('ok','error') NOT NULL DEFAULT 'ok',
                `message`         VARCHAR(255) NULL DEFAULT NULL,
                `date_creation`   DATETIME     NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_source` (`plugin_gitplugins_sources_id`),
                KEY `idx_action` (`action`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}"
        );
    }

    // ---- single-row config (host allowlist, caps, cadence) ----
    if (!$DB->tableExists('glpi_plugin_gitplugins_config')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_gitplugins_config` (
                `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `allowed_hosts`        JSON         NULL DEFAULT NULL,
                `allow_auto_install`   TINYINT(1)   NOT NULL DEFAULT 0,
                `allow_downgrade`      TINYINT(1)   NOT NULL DEFAULT 0,
                `max_download_mb`      SMALLINT UNSIGNED NOT NULL DEFAULT 50,
                `fetch_timeout_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
                `check_frequency_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 1440,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}"
        );
        // Conservative default allowlist (A10): only well-known hosts + our own.
        $DB->insert('glpi_plugin_gitplugins_config', [
            'id'            => 1,
            'allowed_hosts' => json_encode(['github.com', 'api.github.com', 'raw.githubusercontent.com', 'codeload.github.com', 'objects.githubusercontent.com', 'github-releases.githubusercontent.com', 'gitlab.com', 'git.convergent.tn']),
        ]);
    }

    // Idempotent upgrade for instances created under an earlier dev schema.
    plugin_gitplugins_migrate($DB);

    // Grant plugin_gitplugins right (ALLSTANDARDRIGHT) ONLY to profiles that
    // already hold config UPDATE — A01: this is the highest-privilege capability
    // (installs remote code), never granted to ordinary users. Re-login refreshes
    // session rights.
    $profiles = $DB->request([
        'SELECT'  => ['profiles_id'],
        'FROM'    => 'glpi_profilerights',
        'WHERE'   => ['name' => 'config', 'rights' => ['&', UPDATE]],
        'GROUPBY' => 'profiles_id',
    ]);
    foreach ($profiles as $row) {
        $DB->updateOrInsert(
            'glpi_profilerights',
            ['rights' => ALLSTANDARDRIGHT],
            ['profiles_id' => (int) $row['profiles_id'], 'name' => 'plugin_gitplugins']
        );
    }

    CronTask::Register(
        'PluginGitpluginsUpdatecheck',
        'checkUpdates',
        HOUR_TIMESTAMP,
        [
            'comment'      => 'Check managed git sources for updates and run pending installs (resumable)',
            'mode'         => CronTask::MODE_EXTERNAL,
            'allowmode'    => CronTask::MODE_INTERNAL | CronTask::MODE_EXTERNAL,
            'logslifetime' => 30,
            'state'        => CronTask::STATE_WAITING,
        ]
    );

    return true;
}

/**
 * Idempotent column/table top-ups for instances installed under an earlier dev
 * schema (pre-release, so we ALTER in place rather than ship versioned migs).
 */
function plugin_gitplugins_migrate(DBmysql $DB): void
{
    $inst = 'glpi_plugin_gitplugins_installs';
    if ($DB->tableExists($inst)) {
        $cols = [
            'pending_action' => "ADD COLUMN `pending_action` ENUM('none','install','update') NOT NULL DEFAULT 'none'",
            'available_sha'  => "ADD COLUMN `available_sha` VARCHAR(64) NULL DEFAULT NULL",
            'installed_sha'  => "ADD COLUMN `installed_sha` VARCHAR(64) NULL DEFAULT NULL",
        ];
        foreach ($cols as $col => $ddl) {
            if (!$DB->fieldExists($inst, $col)) {
                $DB->doQuery("ALTER TABLE `{$inst}` {$ddl}");
            }
        }
        if (!isIndex($inst, 'idx_pending')) {
            $DB->doQuery("ALTER TABLE `{$inst}` ADD KEY `idx_pending` (`pending_action`)");
        }
    }

    // Widen the ref_policy ENUM to include the new 'release' mode (idempotent —
    // MODIFY is a no-op when the column already carries the value).
    $src = 'glpi_plugin_gitplugins_sources';
    if ($DB->tableExists($src) && $DB->fieldExists($src, 'ref_policy')) {
        $DB->doQuery(
            "ALTER TABLE `{$src}` MODIFY COLUMN `ref_policy` "
            . "ENUM('track_branch','latest_tag','pin_tag','pin_sha','release') "
            . "NOT NULL DEFAULT 'latest_tag'"
        );
    }

    $cfg = 'glpi_plugin_gitplugins_config';
    if ($DB->tableExists($cfg)) {
        $cols = [
            'allow_downgrade'   => "ADD COLUMN `allow_downgrade` TINYINT(1) NOT NULL DEFAULT 0",
            'allow_auto_install' => "ADD COLUMN `allow_auto_install` TINYINT(1) NOT NULL DEFAULT 0",
        ];
        foreach ($cols as $col => $ddl) {
            if (!$DB->fieldExists($cfg, $col)) {
                $DB->doQuery("ALTER TABLE `{$cfg}` {$ddl}");
            }
        }
        // Top up the host allowlist with the GitHub release-download hosts so the
        // 'release' policy passes the SSRF host check on already-installed boxes.
        // Only ADD missing hosts — never remove an admin's custom entries.
        $row = $DB->request(['FROM' => $cfg, 'WHERE' => ['id' => 1], 'LIMIT' => 1])->current();
        if ($row !== null) {
            $hosts = json_decode((string) ($row['allowed_hosts'] ?? ''), true);
            if (is_array($hosts)) {
                $have = array_map(static fn ($h) => strtolower((string) $h), $hosts);
                $add  = ['api.github.com', 'objects.githubusercontent.com', 'github-releases.githubusercontent.com'];
                $new  = $have;
                foreach ($add as $h) {
                    if (!in_array($h, $have, true)) {
                        $new[] = $h;
                    }
                }
                if (count($new) !== count($have)) {
                    $DB->update($cfg, ['allowed_hosts' => json_encode(array_values($new))], ['id' => 1]);
                }
            }
        }
    }
}

function plugin_gitplugins_uninstall(): bool
{
    /** @var DBmysql $DB */
    global $DB;

    CronTask::Unregister('gitplugins');

    $DB->doQuery("DELETE FROM `glpi_profilerights` WHERE `name` = 'plugin_gitplugins'");

    foreach ([
        'glpi_plugin_gitplugins_logs',
        'glpi_plugin_gitplugins_installs',
        'glpi_plugin_gitplugins_sources',
        'glpi_plugin_gitplugins_config',
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    return true;
}
