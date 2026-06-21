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
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key',
                `name`            VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Admin label for the managed repository',
                `url`             VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Normalised git/HTTPS repository URL (CR/LF-stripped, no trailing slash)',
                `host`            VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Lower-cased host parsed from the URL, checked against the SSRF allowlist',
                `provider`        ENUM('github','gitlab','gitea','forgejo','unknown') NOT NULL DEFAULT 'unknown' COMMENT 'Detected forge provider, drives the API/ref-resolution strategy',
                `plugin_key`      VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'GLPI plugin key this source provides (matches the plugin directory/setup key)',
                `ref_policy`      ENUM('track_branch','latest_tag','pin_tag','pin_sha','release') NOT NULL DEFAULT 'latest_tag' COMMENT 'How the target ref is resolved: track_branch|latest_tag|pin_tag|pin_sha|release',
                `ref`             VARCHAR(255) NULL DEFAULT NULL COMMENT 'Concrete ref for the policy (branch/tag name or SHA); NULL when auto-resolved',
                `credential`      TEXT         NULL DEFAULT NULL COMMENT 'GLPIKey-encrypted private-repo token (write-only; never logged or echoed)',
                `entities_id`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Owning GLPI entity (A01 scope)',
                `is_recursive`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Whether the entity scope includes sub-entities',
                `is_active`       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Disabled sources are skipped by the update checker',
                `date_creation`   DATETIME     NULL DEFAULT NULL COMMENT 'Row creation timestamp',
                `date_mod`        DATETIME     NULL DEFAULT NULL COMMENT 'Last modification timestamp',
                PRIMARY KEY (`id`),
                KEY `idx_plugin_key` (`plugin_key`),
                KEY `entities_id`    (`entities_id`),
                KEY `idx_active`     (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} COMMENT='Managed git/HTTPS plugin repositories with ref policy and encrypted credential'"
        );
    }

    // ---- installs: per managed plugin, installed vs available version/SHA ----
    if (!$DB->tableExists('glpi_plugin_gitplugins_installs')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_gitplugins_installs` (
                `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key',
                `plugin_gitplugins_sources_id` INT UNSIGNED NOT NULL COMMENT 'FK to glpi_plugin_gitplugins_sources (one install state per source)',
                `plugin_key`          VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'GLPI plugin key this row tracks',
                `installed_version`   VARCHAR(64)  NULL DEFAULT NULL COMMENT 'Version currently present on disk (from the plugin manifest)',
                `installed_sha`       VARCHAR(64)  NULL DEFAULT NULL COMMENT 'Commit SHA of the installed checkout, when known',
                `available_version`   VARCHAR(64)  NULL DEFAULT NULL COMMENT 'Latest version resolved from the source per its ref policy',
                `available_sha`       VARCHAR(64)  NULL DEFAULT NULL COMMENT 'Commit SHA of the available ref, when known',
                `pending_action`      ENUM('none','install','update') NOT NULL DEFAULT 'none' COMMENT 'Queued action for the cron worker to apply',
                `last_result`         ENUM('none','ok','error','pending') NOT NULL DEFAULT 'none' COMMENT 'Outcome of the most recent install/update attempt',
                `last_error`          VARCHAR(255) NULL DEFAULT NULL COMMENT 'Generic last error message (no secrets)',
                `last_check_at`       DATETIME     NULL DEFAULT NULL COMMENT 'When the source was last checked for updates',
                `last_install_at`     DATETIME     NULL DEFAULT NULL COMMENT 'When an install/update last succeeded',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_source` (`plugin_gitplugins_sources_id`),
                KEY `idx_plugin_key` (`plugin_key`),
                KEY `idx_pending`    (`pending_action`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} COMMENT='Per-source install state: installed vs available version/SHA and pending action'"
        );
    }

    // ---- logs: audit per fetch/install/update (generic messages, no secrets) ----
    if (!$DB->tableExists('glpi_plugin_gitplugins_logs')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_gitplugins_logs` (
                `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key',
                `plugin_gitplugins_sources_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'FK to the source the event concerns (NULL for global events)',
                `users_id`        INT UNSIGNED NULL DEFAULT NULL COMMENT 'GLPI user who triggered the action (NULL for cron)',
                `action`          VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'Audited action (e.g. fetch, install, update)',
                `ref`             VARCHAR(255) NULL DEFAULT NULL COMMENT 'Ref involved (branch/tag/SHA name)',
                `sha`             VARCHAR(64)  NULL DEFAULT NULL COMMENT 'Resolved commit SHA, when known',
                `result`          ENUM('ok','error') NOT NULL DEFAULT 'ok' COMMENT 'Outcome of the action',
                `message`         VARCHAR(255) NULL DEFAULT NULL COMMENT 'Generic human-readable detail (no secrets/credentials)',
                `date_creation`   DATETIME     NULL DEFAULT NULL COMMENT 'When the event was logged',
                PRIMARY KEY (`id`),
                KEY `idx_source` (`plugin_gitplugins_sources_id`),
                KEY `idx_action` (`action`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} COMMENT='Audit log of fetch/install/update actions — generic messages, never secrets'"
        );
    }

    // ---- single-row config (host allowlist, caps, cadence) ----
    if (!$DB->tableExists('glpi_plugin_gitplugins_config')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_gitplugins_config` (
                `id`                   INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key (single config row, id=1)',
                `allowed_hosts`        JSON         NULL DEFAULT NULL COMMENT 'JSON host allowlist for the SSRF-guarded fetcher (A10); empty falls back to a safe default',
                `allow_auto_install`   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Whether the cron worker may auto-install/update without manual approval',
                `allow_downgrade`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Whether installing an older version than the installed one is permitted',
                `max_download_mb`      SMALLINT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'Max archive download size in MB (clamped 1..500)',
                `fetch_timeout_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 30 COMMENT 'Per-fetch network timeout in seconds (clamped 5..300)',
                `check_frequency_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 1440 COMMENT 'Update-check cadence in minutes (clamped 5..40320)',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} COMMENT='Single-row plugin config: SSRF host allowlist, install policy, download/timeout caps'"
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
            'pending_action' => "ADD COLUMN `pending_action` ENUM('none','install','update') NOT NULL DEFAULT 'none' COMMENT 'Queued action for the cron worker to apply'",
            'available_sha'  => "ADD COLUMN `available_sha` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Commit SHA of the available ref, when known'",
            'installed_sha'  => "ADD COLUMN `installed_sha` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Commit SHA of the installed checkout, when known'",
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
            . "NOT NULL DEFAULT 'latest_tag' "
            . "COMMENT 'How the target ref is resolved: track_branch|latest_tag|pin_tag|pin_sha|release'"
        );
    }

    $cfg = 'glpi_plugin_gitplugins_config';
    if ($DB->tableExists($cfg)) {
        $cols = [
            'allow_downgrade'   => "ADD COLUMN `allow_downgrade` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether installing an older version than the installed one is permitted'",
            'allow_auto_install' => "ADD COLUMN `allow_auto_install` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether the cron worker may auto-install/update without manual approval'",
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
