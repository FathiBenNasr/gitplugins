<?php
/**
 * Git Plugin Installer — single-row config accessor (plain singleton).
 *
 * Deliberately does NOT extend CommonDBTM, to avoid accidentally shadowing a
 * framework method (e.g. update()/load()) with reduced visibility — a fatal
 * compile error that took down a sibling plugin once (lesson #2).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsConfig
{
    private static ?self $instance = null;
    private array        $row      = [];

    /** Lazily load and return the single shared config row accessor. */
    public static function singleton(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->reload();
        }

        return self::$instance;
    }

    private function reload(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        if (!$DB->tableExists('glpi_plugin_gitplugins_config')) {
            $this->row = [];

            return;
        }
        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_gitplugins_config',
            'WHERE' => ['id' => 1],
            'LIMIT' => 1,
        ]);
        $this->row = $iter->current() ?: [];
    }

    /**
     * The host allowlist (A10). Lower-cased, de-duplicated. Falls back to a
     * conservative default if the column is empty.
     *
     * @return string[]
     */
    public function getAllowedHosts(): array
    {
        $raw = $this->row['allowed_hosts'] ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw) || $raw === []) {
            $raw = ['github.com', 'api.github.com', 'raw.githubusercontent.com', 'codeload.github.com', 'objects.githubusercontent.com', 'github-releases.githubusercontent.com', 'gitlab.com', 'git.convergent.tn'];
        }
        $out = [];
        foreach ($raw as $h) {
            $h = strtolower(trim((string) $h));
            if ($h !== '' && preg_match('/^[a-z0-9.\-]+$/', $h)) {
                $out[$h] = $h;
            }
        }

        return array_values($out);
    }

    /** Whether the cron worker may auto-install/update without manual approval. */
    public function allowAutoInstall(): bool
    {
        return (bool) ($this->row['allow_auto_install'] ?? false);
    }

    /** Whether installing a version older than the installed one is permitted. */
    public function allowDowngrade(): bool
    {
        return (bool) ($this->row['allow_downgrade'] ?? false);
    }

    /** Max archive download size in MB (clamped 1..500). */
    public function getMaxDownloadMb(): int
    {
        return max(1, min(500, (int) ($this->row['max_download_mb'] ?? 50)));
    }

    /** Max archive download size in bytes (getMaxDownloadMb * 1024 * 1024). */
    public function getMaxDownloadBytes(): int
    {
        return $this->getMaxDownloadMb() * 1024 * 1024;
    }

    /** Per-fetch network timeout in seconds (clamped 5..300). */
    public function getFetchTimeoutSeconds(): int
    {
        return max(5, min(300, (int) ($this->row['fetch_timeout_seconds'] ?? 30)));
    }

    /** Update-check cadence in minutes (clamped 5..40320). */
    public function getCheckFrequencyMinutes(): int
    {
        return max(5, min(40320, (int) ($this->row['check_frequency_minutes'] ?? 1440)));
    }

    /** Whether the digest cron e-mails admins about available updates (default on). */
    public function notifyUpdates(): bool
    {
        return (bool) ($this->row['notify_updates'] ?? true);
    }

    /** Optional explicit digest recipient override ('' = fall back to Super-Admins). */
    public function getNotifyRecipient(): string
    {
        return trim((string) ($this->row['notify_recipient'] ?? ''));
    }

    /**
     * Runtime-built dirs preserved from the old tree across an update (so an
     * optional one-click build — e.g. assetreport's mPDF vendor/ — is not wiped).
     * Defaults to vendor + node_modules. Only plain, safe dir names are honoured.
     *
     * @return string[]
     */
    public function getCarryOverDirs(): array
    {
        $raw = $this->row['carry_over_dirs'] ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return ['vendor', 'node_modules'];
        }
        $out = [];
        foreach ($raw as $d) {
            $d = trim((string) $d);
            if ($d !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $d)) {
                $out[$d] = $d;
            }
        }

        return $out === [] ? ['vendor', 'node_modules'] : array_values($out);
    }

    /** Whether to clear GLPI caches after activate (default on). */
    public function autoCacheClear(): bool
    {
        return (bool) ($this->row['auto_cache_clear'] ?? true);
    }

    /** Per-build wall-clock cap for composer/npm build steps (clamped 30..1800). */
    public function getBuildTimeoutSeconds(): int
    {
        return max(30, min(1800, (int) ($this->row['build_timeout_seconds'] ?? 300)));
    }

    /**
     * Size cap (MB) for a pre-migration DB snapshot; over this we skip + warn
     * rather than block the update. 0 = unlimited. Clamped 0..10000.
     */
    public function getSnapshotMaxMb(): int
    {
        return max(0, min(10000, (int) ($this->row['snapshot_max_mb'] ?? 100)));
    }

    /**
     * Whether LOCAL/dev sources are permitted at all (Phase 1). OFF by default —
     * local sources read the server filesystem, so they must be explicitly
     * enabled and are unsafe on multi-tenant/hosted installs.
     */
    public function allowLocalSources(): bool
    {
        return (bool) ($this->row['allow_local_sources'] ?? false);
    }

    /**
     * Absolute-path root allowlist a local source may live under. Only absolute,
     * NUL/CRLF-free paths are honoured; empty = nothing allowed (fail closed).
     *
     * @return string[]
     */
    public function getLocalSourceRoots(): array
    {
        $raw = $this->row['local_source_roots'] ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $p) {
            $p = str_replace(["\r", "\n", "\0"], '', trim((string) $p));
            if ($p !== '' && $p[0] === '/') {
                $out[$p] = $p;
            }
        }

        return array_values($out);
    }

    /** How many pre-update snapshots to retain per plugin for rollback (0..50). */
    public function getRollbackKeep(): int
    {
        return max(0, min(50, (int) ($this->row['rollback_keep'] ?? 3)));
    }

    /** Action on a FAILED post-install health check: 'flag' (default) or 'rollback'. */
    public function healthFailAction(): string
    {
        $v = strtolower(trim((string) ($this->row['health_fail_action'] ?? 'flag')));

        return $v === 'rollback' ? 'rollback' : 'flag';
    }

    /**
     * The convergent catalog manifest URL (Phase 10). '' when unset. Only an
     * https URL with a host is returned; the SSRF host-allowlist is re-checked at
     * fetch time (defence in depth).
     */
    public function getCatalogUrl(): string
    {
        $url = str_replace(["\r", "\n", "\0", ' '], '', trim((string) ($this->row['catalog_url'] ?? '')));
        if ($url === '') {
            return '';
        }
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || (string) parse_url($url, PHP_URL_HOST) === '') {
            return '';
        }

        return $url;
    }

    /**
     * Validate + persist config fields from the config form. Named saveFields()
     * (NOT update()) to avoid any CommonDBTM clash.
     */
    public function saveFields(array $post): void
    {
        /** @var DBmysql $DB */
        global $DB;

        // Parse the allowlist textarea (one host per line), validating each.
        $hosts = [];
        foreach (preg_split('/[\s,]+/', (string) ($post['allowed_hosts'] ?? '')) ?: [] as $h) {
            $h = strtolower(trim($h));
            if ($h !== '' && preg_match('/^[a-z0-9.\-]+$/', $h) && strlen($h) <= 253) {
                $hosts[$h] = $h;
            }
        }
        if ($hosts === []) {
            // Never allow an empty allowlist (would be a wide-open SSRF surface);
            // keep the safe default.
            $hosts = ['github.com', 'api.github.com', 'raw.githubusercontent.com', 'codeload.github.com', 'objects.githubusercontent.com', 'github-releases.githubusercontent.com', 'gitlab.com', 'git.convergent.tn'];
        }

        // Digest recipient override: validate as an e-mail, CR/LF-strip; blank ok.
        $recipient = str_replace(["\r", "\n", "\0"], '', trim((string) ($post['notify_recipient'] ?? '')));
        if ($recipient !== '' && !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $recipient = '';
        }

        // Catalog manifest URL (Phase 10): keep only a clean https URL with a host;
        // blank otherwise. The SSRF host-allowlist is enforced at fetch time.
        $catalogUrl = str_replace(["\r", "\n", "\0", ' '], '', trim((string) ($post['catalog_url'] ?? '')));
        if ($catalogUrl !== '' && (strtolower((string) parse_url($catalogUrl, PHP_URL_SCHEME)) !== 'https' || (string) parse_url($catalogUrl, PHP_URL_HOST) === '')) {
            $catalogUrl = '';
        }

        // Local-source roots (one absolute path per line). Only absolute,
        // NUL/CRLF-free paths are kept; anything else is dropped (fail closed).
        $roots = [];
        foreach (preg_split('/[\r\n]+/', (string) ($post['local_source_roots'] ?? '')) ?: [] as $p) {
            $p = str_replace("\0", '', trim($p));
            if ($p !== '' && $p[0] === '/') {
                $roots[$p] = mb_substr($p, 0, 255);
            }
        }

        $data = [
            'allowed_hosts'          => json_encode(array_values($hosts)),
            'allow_auto_install'     => isset($post['allow_auto_install']) ? 1 : 0,
            'allow_downgrade'        => isset($post['allow_downgrade']) ? 1 : 0,
            'max_download_mb'        => max(1, min(500, (int) ($post['max_download_mb'] ?? 50))),
            'fetch_timeout_seconds'  => max(5, min(300, (int) ($post['fetch_timeout_seconds'] ?? 30))),
            'check_frequency_minutes' => max(5, min(40320, (int) ($post['check_frequency_minutes'] ?? 1440))),
            'notify_updates'         => isset($post['notify_updates']) ? 1 : 0,
            'notify_recipient'       => $recipient !== '' ? mb_substr($recipient, 0, 255) : null,
            'allow_local_sources'    => isset($post['allow_local_sources']) ? 1 : 0,
            'local_source_roots'     => $roots === [] ? null : json_encode(array_values($roots)),
            'rollback_keep'          => max(0, min(50, (int) ($post['rollback_keep'] ?? 3))),
            'health_fail_action'     => (($post['health_fail_action'] ?? 'flag') === 'rollback') ? 'rollback' : 'flag',
            'catalog_url'            => $catalogUrl !== '' ? mb_substr($catalogUrl, 0, 255) : null,
        ];
        $DB->update('glpi_plugin_gitplugins_config', $data, ['id' => 1]);
        self::$instance = null;
    }
}
