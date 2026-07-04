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
                `provider`        ENUM('github','gitlab','gitea','forgejo','local','unknown') NOT NULL DEFAULT 'unknown' COMMENT 'Detected forge provider (or local filesystem source), drives the acquire/ref-resolution strategy',
                `plugin_key`      VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'GLPI plugin key this source provides (matches the plugin directory/setup key)',
                `ref_policy`      ENUM('track_branch','latest_tag','pin_tag','pin_sha','release') NOT NULL DEFAULT 'latest_tag' COMMENT 'How the target ref is resolved: track_branch|latest_tag|pin_tag|pin_sha|release',
                `ref`             VARCHAR(255) NULL DEFAULT NULL COMMENT 'Concrete ref for the policy (branch/tag name or SHA); NULL when auto-resolved',
                `credential`      TEXT         NULL DEFAULT NULL COMMENT 'GLPIKey-encrypted private-repo token (write-only; never logged or echoed)',
                `entities_id`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Owning GLPI entity (A01 scope)',
                `is_recursive`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Whether the entity scope includes sub-entities',
                `is_active`       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Disabled sources are skipped by the update checker',
                `build_on_install` TINYINT(1)  NOT NULL DEFAULT 0 COMMENT 'Opt-in: run composer/npm build in the staged tree before install (runs third-party build code; OFF by default)',
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
                `update_available`    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Cached flag: 1 when the available version/SHA differs from the installed one (drives the UI badge, no fetch at render)',
                `pending_action`      ENUM('none','install','update') NOT NULL DEFAULT 'none' COMMENT 'Queued action for the cron worker to apply',
                `last_result`         ENUM('none','ok','error','pending') NOT NULL DEFAULT 'none' COMMENT 'Outcome of the most recent install/update attempt',
                `last_error`          VARCHAR(255) NULL DEFAULT NULL COMMENT 'Generic last error message (no secrets)',
                `last_check_at`       DATETIME     NULL DEFAULT NULL COMMENT 'When the source was last checked for updates',
                `last_install_at`     DATETIME     NULL DEFAULT NULL COMMENT 'When an install/update last succeeded',
                `last_notified_sha`   VARCHAR(64)  NULL DEFAULT NULL COMMENT 'available_version|available_sha last included in an emailed digest (anti-spam: re-send only when the available set changes)',
                `last_notified_at`    DATETIME     NULL DEFAULT NULL COMMENT 'When this row was last included in an emailed update digest',
                `health`              ENUM('ok','warn','fail','unknown') NOT NULL DEFAULT 'unknown' COMMENT 'Post-install self-check verdict from the target plugin (prerequisites/config), beyond mere activation',
                `health_detail`       VARCHAR(255) NULL DEFAULT NULL COMMENT 'Generic detail for a non-ok health verdict (no secrets)',
                `hook_warnings`       JSON         NULL DEFAULT NULL COMMENT 'Cached JSON list of post-install $PLUGIN_HOOKS collisions with other active plugins (Phase 6 badge)',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_plugin_key` (`plugin_key`),
                KEY `idx_source`     (`plugin_gitplugins_sources_id`),
                KEY `idx_update`     (`update_available`),
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

    // ---- snapshots: retained pre-update file backup + DB dump for rollback ----
    if (!$DB->tableExists('glpi_plugin_gitplugins_snapshots')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_gitplugins_snapshots` (
                `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key',
                `plugin_key`        VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'Managed plugin key this snapshot belongs to',
                `plugin_gitplugins_sources_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Source the update ran from (NULL if unknown)',
                `version`           VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'The plugin version captured (the version being replaced)',
                `sha`               VARCHAR(64)  NULL DEFAULT NULL COMMENT 'Installed commit SHA at capture time, when known',
                `files_archive_path` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Absolute path to the inert out-of-web-tree file backup zip (R2)',
                `db_dump_path`      VARCHAR(255) NULL DEFAULT NULL COMMENT 'Absolute path to the gzipped owned-tables dump (R8), when captured',
                `date_creation`     DATETIME     NULL DEFAULT NULL COMMENT 'When the snapshot was captured',
                PRIMARY KEY (`id`),
                KEY `idx_plugin_key` (`plugin_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} COMMENT='Retained pre-update snapshots (files + owned DB tables) for one-click version rollback'"
        );
    }

    // ---- known_issues: curated conflict/advisory registry (Phase 7) ----
    if (!$DB->tableExists('glpi_plugin_gitplugins_known_issues')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_gitplugins_known_issues` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key',
                `plugin_key`    VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'Plugin the issue concerns (* = any plugin, a global advisory)',
                `version_range` VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'Affected version range of plugin_key (whitespace-AND comparators or A - B; empty = any)',
                `kind`          ENUM('conflict','advisory','min_peer') NOT NULL DEFAULT 'advisory' COMMENT 'conflict (bad peer combo) | advisory (free-text warning) | min_peer (peer present but out of required range)',
                `peer_key`      VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'The peer plugin for conflict/min_peer kinds (empty for advisory)',
                `peer_range`    VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'Peer version range the rule applies to (empty = any installed version)',
                `message`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Generic human-readable advisory (no secrets)',
                `source`        VARCHAR(64)  NOT NULL DEFAULT 'builtin' COMMENT 'Origin of the row (builtin = shipped seed, or a catalog id); refreshed wholesale per source',
                `date_creation` DATETIME     NULL DEFAULT NULL COMMENT 'When the row was seeded',
                PRIMARY KEY (`id`),
                KEY `idx_plugin_key` (`plugin_key`),
                KEY `idx_source`     (`source`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} COMMENT='Curated known-bad plugin combinations / advisories consulted at install-confirm'"
        );
    }

    // ---- catalog: cached convergent plugin catalog (Phase 10) ----
    if (!$DB->tableExists('glpi_plugin_gitplugins_catalog')) {
        $DB->doQuery(
            "CREATE TABLE `glpi_plugin_gitplugins_catalog` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Primary key',
                `plugin_key`  VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'GLPI plugin key from the catalog manifest',
                `name`        VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Display name',
                `url`         VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'HTTPS repository URL',
                `ref_policy`  VARCHAR(32)  NOT NULL DEFAULT 'latest_tag' COMMENT 'Recommended ref policy for this plugin',
                `category`    VARCHAR(64)  NOT NULL DEFAULT '' COMMENT 'Grouping category',
                `description` VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Short description',
                `updated_at`  DATETIME     NULL DEFAULT NULL COMMENT 'When this row was last refreshed from the manifest',
                PRIMARY KEY (`id`),
                KEY `idx_plugin_key` (`plugin_key`),
                KEY `idx_category`   (`category`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} COMMENT='Cached convergent plugin catalog (advisory browse+prefill; every install still confirmed)'"
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
                `notify_updates`       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Whether the digest cron emails admins when managed plugins have updates available',
                `notify_recipient`     VARCHAR(255) NULL DEFAULT NULL COMMENT 'Optional explicit digest recipient email override; empty falls back to Super-Admin users / GLPI admin_email',
                `carry_over_dirs`      JSON         NULL DEFAULT NULL COMMENT 'JSON list of runtime-built dir names preserved across an update (default vendor + node_modules)',
                `auto_cache_clear`     TINYINT(1)   NOT NULL DEFAULT 1 COMMENT 'Whether to clear GLPI caches after a plugin install/activate (avoids stale-route 404s)',
                `build_timeout_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 300 COMMENT 'Per-build wall-clock cap for composer/npm build steps in seconds (clamped 30..1800)',
                `snapshot_max_mb`      SMALLINT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'Size cap in MB for the pre-migration DB snapshot; over this we skip+warn (0 = unlimited)',
                `allow_local_sources`  TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Whether LOCAL/dev filesystem sources are permitted (OFF by default; unsafe on hosted installs)',
                `local_source_roots`   JSON         NULL DEFAULT NULL COMMENT 'JSON allowlist of absolute path roots a LOCAL source may live under (empty = none)',
                `rollback_keep`        SMALLINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'How many pre-update snapshots to retain per plugin for rollback (0 = keep none; clamped 0..50)',
                `health_fail_action`   ENUM('flag','rollback') NOT NULL DEFAULT 'flag' COMMENT 'What to do when a post-install health check FAILS: flag red (keep active) or auto-rollback',
                `catalog_url`          VARCHAR(255) NULL DEFAULT NULL COMMENT 'Convergent plugin catalog manifest URL (SSRF-allowlisted; advisory browse+prefill)',
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

    // Seed / refresh the shipped known-issues registry (Phase 7). Idempotent:
    // replaces the 'builtin' source rows wholesale, leaving any admin/catalog
    // rows untouched. Best-effort — never fails the install.
    if (class_exists('PluginGitpluginsKnownissues')) {
        PluginGitpluginsKnownissues::seedFromShipped();
    }

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

    // Daily digest e-mail of plugins with an available update (marketplace
    // parity). Separate task so its cadence is independent of the 5-min check.
    CronTask::Register(
        'PluginGitpluginsUpdatecheck',
        'notifyUpdates',
        DAY_TIMESTAMP,
        [
            'comment'      => 'Email a digest of managed plugins that have an available update',
            'mode'         => CronTask::MODE_EXTERNAL,
            'allowmode'    => CronTask::MODE_INTERNAL | CronTask::MODE_EXTERNAL,
            'logslifetime' => 30,
            'state'        => CronTask::STATE_WAITING,
        ]
    );

    // Apply-queued-updates worker (Phase 9 bulk apply). Shorter cadence than the
    // full check so a bulk "Update selected" applies promptly; runs the same
    // Phase 0 pipeline, each install independently verified/rolled-back.
    CronTask::Register(
        'PluginGitpluginsUpdatecheck',
        'applyUpdates',
        15 * MINUTE_TIMESTAMP,
        [
            'comment'      => 'Apply plugin installs/updates queued from the bulk dry-run',
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
            'pending_action'    => "ADD COLUMN `pending_action` ENUM('none','install','update') NOT NULL DEFAULT 'none' COMMENT 'Queued action for the cron worker to apply'",
            'available_sha'     => "ADD COLUMN `available_sha` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Commit SHA of the available ref, when known'",
            'installed_sha'     => "ADD COLUMN `installed_sha` VARCHAR(64) NULL DEFAULT NULL COMMENT 'Commit SHA of the installed checkout, when known'",
            'update_available'  => "ADD COLUMN `update_available` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Cached flag: 1 when the available version/SHA differs from the installed one (drives the UI badge, no fetch at render)'",
            'last_notified_sha' => "ADD COLUMN `last_notified_sha` VARCHAR(64) NULL DEFAULT NULL COMMENT 'available_version|available_sha last included in an emailed digest (anti-spam: re-send only when the available set changes)'",
            'last_notified_at'  => "ADD COLUMN `last_notified_at` DATETIME NULL DEFAULT NULL COMMENT 'When this row was last included in an emailed update digest'",
            'health'            => "ADD COLUMN `health` ENUM('ok','warn','fail','unknown') NOT NULL DEFAULT 'unknown' COMMENT 'Post-install self-check verdict from the target plugin (prerequisites/config), beyond mere activation'",
            'health_detail'     => "ADD COLUMN `health_detail` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Generic detail for a non-ok health verdict (no secrets)'",
            'hook_warnings'     => "ADD COLUMN `hook_warnings` JSON NULL DEFAULT NULL COMMENT 'Cached JSON list of post-install \$PLUGIN_HOOKS collisions with other active plugins (Phase 6 badge)'",
        ];
        foreach ($cols as $col => $ddl) {
            if (!$DB->fieldExists($inst, $col)) {
                $DB->doQuery("ALTER TABLE `{$inst}` {$ddl}");
            }
        }

        // FIX 1: install state is now keyed by plugin_key (one row per managed
        // plugin), not by source. De-dupe any rows that share a plugin_key —
        // keeping the most recently touched — BEFORE adding the unique key, or
        // the ALTER would fail on duplicates. Then swap uniq_source → uniq_key.
        if (!isIndex($inst, 'uniq_plugin_key')) {
            // De-dupe rows sharing a plugin_key (keep the newest by id), in PHP —
            // robust across DB iterator versions, idempotent, no HAVING needed.
            $seen = [];
            foreach ($DB->request(['FROM' => $inst, 'ORDER' => 'id DESC']) as $r) {
                $k = (string) ($r['plugin_key'] ?? '');
                if ($k === '') {
                    continue;
                }
                if (isset($seen[$k])) {
                    // A newer row for this key already kept → delete this older one.
                    $DB->delete($inst, ['id' => (int) $r['id']]);
                } else {
                    $seen[$k] = (int) $r['id'];
                }
            }

            if (isIndex($inst, 'uniq_source')) {
                $DB->doQuery("ALTER TABLE `{$inst}` DROP INDEX `uniq_source`");
            }
            $DB->doQuery("ALTER TABLE `{$inst}` ADD UNIQUE KEY `uniq_plugin_key` (`plugin_key`)");
        }
        if (!isIndex($inst, 'idx_source')) {
            $DB->doQuery("ALTER TABLE `{$inst}` ADD KEY `idx_source` (`plugin_gitplugins_sources_id`)");
        }
        if (!isIndex($inst, 'idx_update')) {
            $DB->doQuery("ALTER TABLE `{$inst}` ADD KEY `idx_update` (`update_available`)");
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

    // Add the R5 per-source build opt-in on already-installed boxes.
    if ($DB->tableExists($src) && !$DB->fieldExists($src, 'build_on_install')) {
        $DB->doQuery(
            "ALTER TABLE `{$src}` ADD COLUMN `build_on_install` TINYINT(1) NOT NULL DEFAULT 0 "
            . "COMMENT 'Opt-in: run composer/npm build in the staged tree before install (runs third-party build code; OFF by default)'"
        );
    }

    // Widen the provider ENUM to include 'local' (Phase 1). Idempotent MODIFY.
    if ($DB->tableExists($src) && $DB->fieldExists($src, 'provider')) {
        $DB->doQuery(
            "ALTER TABLE `{$src}` MODIFY COLUMN `provider` "
            . "ENUM('github','gitlab','gitea','forgejo','local','unknown') NOT NULL DEFAULT 'unknown' "
            . "COMMENT 'Detected forge provider (or local filesystem source), drives the acquire/ref-resolution strategy'"
        );
    }

    $cfg = 'glpi_plugin_gitplugins_config';
    if ($DB->tableExists($cfg)) {
        $cols = [
            'allow_downgrade'   => "ADD COLUMN `allow_downgrade` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether installing an older version than the installed one is permitted'",
            'allow_auto_install' => "ADD COLUMN `allow_auto_install` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether the cron worker may auto-install/update without manual approval'",
            'notify_updates'    => "ADD COLUMN `notify_updates` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether the digest cron emails admins when managed plugins have updates available'",
            'notify_recipient'  => "ADD COLUMN `notify_recipient` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Optional explicit digest recipient email override; empty falls back to Super-Admin users / GLPI admin_email'",
            'carry_over_dirs'   => "ADD COLUMN `carry_over_dirs` JSON NULL DEFAULT NULL COMMENT 'JSON list of runtime-built dir names preserved across an update (default vendor + node_modules)'",
            'auto_cache_clear'  => "ADD COLUMN `auto_cache_clear` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether to clear GLPI caches after a plugin install/activate (avoids stale-route 404s)'",
            'build_timeout_seconds' => "ADD COLUMN `build_timeout_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 300 COMMENT 'Per-build wall-clock cap for composer/npm build steps in seconds (clamped 30..1800)'",
            'snapshot_max_mb'   => "ADD COLUMN `snapshot_max_mb` SMALLINT UNSIGNED NOT NULL DEFAULT 100 COMMENT 'Size cap in MB for the pre-migration DB snapshot; over this we skip+warn (0 = unlimited)'",
            'allow_local_sources' => "ADD COLUMN `allow_local_sources` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether LOCAL/dev filesystem sources are permitted (OFF by default; unsafe on hosted installs)'",
            'local_source_roots' => "ADD COLUMN `local_source_roots` JSON NULL DEFAULT NULL COMMENT 'JSON allowlist of absolute path roots a LOCAL source may live under (empty = none)'",
            'rollback_keep'     => "ADD COLUMN `rollback_keep` SMALLINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'How many pre-update snapshots to retain per plugin for rollback (0 = keep none; clamped 0..50)'",
            'health_fail_action' => "ADD COLUMN `health_fail_action` ENUM('flag','rollback') NOT NULL DEFAULT 'flag' COMMENT 'What to do when a post-install health check FAILS: flag red (keep active) or auto-rollback'",
            'catalog_url'       => "ADD COLUMN `catalog_url` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Convergent plugin catalog manifest URL (SSRF-allowlisted; advisory browse+prefill)'",
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

    // Best-effort: remove retained rollback snapshot files (inert out-of-web-tree
    // backups + DB dumps) before dropping the table that indexes them, so nothing
    // is orphaned on disk.
    if (class_exists('PluginGitpluginsRollback')) {
        PluginGitpluginsRollback::purgeAll($DB);
    }

    foreach ([
        'glpi_plugin_gitplugins_logs',
        'glpi_plugin_gitplugins_snapshots',
        'glpi_plugin_gitplugins_known_issues',
        'glpi_plugin_gitplugins_catalog',
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
