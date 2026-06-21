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

        $data = [
            'allowed_hosts'          => json_encode(array_values($hosts)),
            'allow_auto_install'     => isset($post['allow_auto_install']) ? 1 : 0,
            'allow_downgrade'        => isset($post['allow_downgrade']) ? 1 : 0,
            'max_download_mb'        => max(1, min(500, (int) ($post['max_download_mb'] ?? 50))),
            'fetch_timeout_seconds'  => max(5, min(300, (int) ($post['fetch_timeout_seconds'] ?? 30))),
            'check_frequency_minutes' => max(5, min(40320, (int) ($post['check_frequency_minutes'] ?? 1440))),
            'notify_updates'         => isset($post['notify_updates']) ? 1 : 0,
            'notify_recipient'       => $recipient !== '' ? mb_substr($recipient, 0, 255) : null,
        ];
        $DB->update('glpi_plugin_gitplugins_config', $data, ['id' => 1]);
        self::$instance = null;
    }
}
