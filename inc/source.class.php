<?php
/**
 * Git Plugin Installer — managed git source model + admin menu.
 *
 * One row = one managed repository (URL + ref policy + optional encrypted
 * credential). Helper methods are named distinctively so they never shadow a
 * CommonDBTM method with reduced visibility (lesson #2 — that 500s every page).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

class PluginGitpluginsSource extends CommonDBTM
{
    public static $rightname = 'plugin_gitplugins';

    public static function getTypeName($nb = 0): string
    {
        return _n('Git plugin source', 'Git plugin sources', $nb, 'gitplugins');
    }

    public static function getMenuName(): string
    {
        return __('Git Plugin Installer', 'gitplugins');
    }

    public static function getIcon(): string
    {
        return 'ti ti-git-branch';
    }

    public static function getMenuContent(): array
    {
        $root = PLUGIN_GITPLUGINS_ROOTDOC;

        return [
            'title' => self::getMenuName(),
            'page'  => $root . '/front/source.php',
            'icon'  => self::getIcon(),
            'links' => [
                __('Sources', 'gitplugins')            => $root . '/front/source.php',
                "<i class='ti ti-plus'></i>"           => $root . '/front/source.form.php',
                __('Installed plugins', 'gitplugins')  => $root . '/front/discovered.php',
                __('Status', 'gitplugins')             => $root . '/front/status.php',
                __('Configuration', 'gitplugins')      => $root . '/front/config.php',
            ],
        ];
    }

    /**
     * Normalise an admin-supplied repo URL: trim, strip CR/LF, drop a trailing
     * slash. Does NOT widen scope — the SSRF guard re-validates scheme/host.
     */
    public static function normaliseUrl(string $url): string
    {
        $url = str_replace(["\r", "\n", "\0", ' '], '', trim($url));

        return rtrim($url, '/');
    }

    /** Best-effort provider detection from the host (default 'unknown'). */
    public static function deriveProvider(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return 'unknown';
        }
        if (str_contains($host, 'github')) {
            return 'github';
        }
        if (str_contains($host, 'gitlab')) {
            return 'gitlab';
        }
        if (str_contains($host, 'forgejo') || str_contains($host, 'convergent')) {
            return 'forgejo';
        }
        if (str_contains($host, 'gitea') || str_contains($host, 'codeberg')) {
            return 'gitea';
        }

        return 'unknown';
    }

    /** Host component of a URL ('' if unparseable). */
    public static function hostOf(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    /**
     * Encrypt a private-repo credential with the per-install GLPIKey, so tokens
     * are never stored in plaintext (and never logged/echoed). Empty → null.
     */
    public static function encryptCredential(string $plain): ?string
    {
        $plain = trim($plain);
        if ($plain === '') {
            return null;
        }

        return (new \GLPIKey())->encrypt($plain);
    }

    /** Decrypt a stored credential for use as a fetch auth header. '' on absence. */
    public static function decryptCredential(?string $stored): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        return (string) (new \GLPIKey())->decrypt($stored);
    }

    /**
     * The active sources the current session may act on, as full rows. Entity-
     * scoped (A01). Used by the cron checker + status page.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function activeRows(bool $entityScoped = true): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $where = ['is_active' => 1];
        if ($entityScoped) {
            $where += getEntitiesRestrictCriteria(self::getTable(), '', '', true);
        }
        $out = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => 'name']) as $r) {
            $out[(int) $r['id']] = $r;
        }

        return $out;
    }
}
