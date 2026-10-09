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

    /**
     * WHY: the GLPIKey-encrypted repo token must never leave the server, not
     * even encrypted, through the REST API or any generic item export.
     */
    public static $undisclosedFields = ['credential'];

    /** Ref policies a source may use. */
    public const REF_POLICIES = ['track_branch', 'latest_tag', 'pin_tag', 'pin_sha', 'release'];

    /**
     * PURE: validate and normalise a source as submitted (form, REST API,
     * massive action…) — the ONE place the business rules live, so a write
     * that bypasses front/source.form.php cannot bypass them (L-27).
     *
     * $in keys: name, url, plugin_key, ref_policy, ref, is_active (bool-ish),
     * source_type ('git'|'local'). Returns the errors (empty = valid) and the
     * normalised columns (name, url, host, provider, plugin_key, ref_policy,
     * ref, is_active).
     *
     * @param array<string,mixed>    $in
     * @param string[]               $allowedHosts   SSRF host allowlist
     * @param string[]               $localRoots     allowed roots for local sources
     * @param callable(string): bool $isMarketplace  is this key managed by GLPI's marketplace?
     * @return array{errors: string[], data: array<string,mixed>}
     */
    public static function validateInput(
        array $in,
        array $allowedHosts,
        bool $allowLocal,
        array $localRoots,
        callable $isMarketplace
    ): array {
        $errors  = [];
        $isLocal = (string) ($in['source_type'] ?? 'git') === 'local' && $allowLocal;
        $key     = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string) ($in['plugin_key'] ?? '')) ?? '');
        $policy  = (string) ($in['ref_policy'] ?? 'latest_tag');
        $ref     = trim((string) ($in['ref'] ?? ''));

        if ($isLocal) {
            // LOCAL source (Phase 1): no HTTPS/host/ref checks — the "URL" is an
            // absolute filesystem path that MUST sit under the configured allowlist.
            $url    = str_replace(["\r", "\n", "\0"], '', trim((string) ($in['url'] ?? '')));
            $host   = '';
            $policy = 'latest_tag';
            $ref    = '';
            if (!PluginGitpluginsLocalsource::pathAllowed($url, $localRoots)) {
                $errors[] = __('The local path must be an absolute path under an allowed root (see Configuration).', 'gitplugins');
            }
        } else {
            $url = self::normaliseUrl((string) ($in['url'] ?? ''));
            if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || self::hostOf($url) === '') {
                $errors[] = __('The repository URL must be an https:// URL.', 'gitplugins');
            }
            if (!in_array($policy, self::REF_POLICIES, true)) {
                $policy = 'latest_tag';
            }
            // latest_tag and release resolve a ref themselves; pinned policies need one.
            $refOptional = in_array($policy, ['latest_tag', 'release'], true);
            if (!$refOptional && ($ref === '' || !PluginGitpluginsRefResolver::isValidRef($ref))) {
                $errors[] = __('A valid ref (branch, tag or commit SHA) is required for this policy.', 'gitplugins');
            }
            if ($policy === 'release' && $ref !== '' && !PluginGitpluginsRefResolver::isValidRef($ref)) {
                $errors[] = __('The release tag is not a valid ref.', 'gitplugins');
            }
            // Host allowlist enforcement at save time (A10 defence in depth).
            $host = self::hostOf($url);
            if ($host !== '' && !in_array($host, array_map('strtolower', $allowedHosts), true)) {
                $errors[] = sprintf(__('Host "%s" is not in the allowed-hosts list (see Configuration).', 'gitplugins'), $host);
            }
        }

        if ($key === '') {
            $errors[] = __('A plugin key (lowercase letters, digits, underscore) is required.', 'gitplugins');
        } elseif ($isMarketplace($key)) {
            // WHY (L-15): GLPI loads the marketplace copy first; a second copy
            // under plugins/ would never run while being reported as applied —
            // and would fight GLPI's own marketplace updater.
            $errors[] = __('This plugin is managed by the GLPI marketplace; update it from the marketplace.', 'gitplugins');
        }

        $name = mb_substr(str_replace(["\r", "\n", "\0"], '', trim((string) ($in['name'] ?? ''))), 0, 255);

        return [
            'errors' => $errors,
            'data'   => [
                'name'       => $name !== '' ? $name : $key,
                'url'        => mb_substr($url, 0, 255),
                'host'       => mb_substr($host, 0, 255),
                'provider'   => $isLocal ? 'local' : self::deriveProvider($url),
                'plugin_key' => mb_substr($key, 0, 64),
                'ref_policy' => $policy,
                'ref'        => $ref !== '' ? mb_substr($ref, 0, 255) : null,
                'is_active'  => !empty($in['is_active']) ? 1 : 0,
            ],
        ];
    }

    /** Model-level guard for every creation path (form, API, massive action). */
    public function prepareInputForAdd($input)
    {
        return $this->guardInput(is_array($input) ? $input : [], []);
    }

    /** Model-level guard for every modification path (form, API, massive action). */
    public function prepareInputForUpdate($input)
    {
        return $this->guardInput(is_array($input) ? $input : [], is_array($this->fields) ? $this->fields : []);
    }

    /**
     * Apply validateInput() to the merged row and the column rules that the form
     * used to apply alone. Returns the cleaned input, or false (with the
     * reasons queued for display) when the row would be invalid.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $current the stored row ([] on creation)
     * @return array<string,mixed>|false
     */
    private function guardInput(array $input, array $current)
    {
        // WHY: build_on_install runs third-party build code (composer/npm) and
        // no screen offers it; it must not be switched on through the API.
        unset($input['build_on_install']);

        $ruled = ['name', 'url', 'host', 'provider', 'plugin_key', 'ref_policy', 'ref', 'source_type'];
        if ($current === [] || array_intersect_key($input, array_flip($ruled)) !== []) {
            $merged = array_merge($current, $input);
            if (!isset($input['source_type'])) {
                $merged['source_type'] = (string) ($current['provider'] ?? '') === 'local' ? 'local' : 'git';
            }
            if (!array_key_exists('is_active', $input)) {
                $merged['is_active'] = (int) ($current['is_active'] ?? 1);
            }
            $cfg     = PluginGitpluginsConfig::singleton();
            $checked = self::validateInput(
                $merged,
                $cfg->getAllowedHosts(),
                $cfg->allowLocalSources(),
                $cfg->getLocalSourceRoots(),
                static fn (string $k): bool => PluginGitpluginsDiscovery::isMarketplacePlugin($k)
            );
            if ($checked['errors'] !== []) {
                foreach ($checked['errors'] as $e) {
                    Session::addMessageAfterRedirect(htmlspecialchars($e, ENT_QUOTES), false, ERROR);
                }

                return false;
            }
            $input = array_merge($input, $checked['data']);
        }
        unset($input['source_type']);
        if (array_key_exists('is_active', $input)) {
            $input['is_active'] = !empty($input['is_active']) ? 1 : 0;
        }

        // Credential: plaintext in, GLPIKey ciphertext stored — on every path.
        // Empty keeps the stored one; `_clear_credential` removes it.
        if (array_key_exists('credential', $input)) {
            $plain = trim((string) $input['credential']);
            if ($plain === '') {
                unset($input['credential']);
            } else {
                $input['credential'] = self::encryptCredential($plain);
            }
        }
        if (!empty($input['_clear_credential'])) {
            $input['credential'] = null;
        }
        unset($input['_clear_credential']);

        return $input;
    }

    /** Localised type name (singular/plural) for GLPI UI labels. */
    public static function getTypeName($nb = 0): string
    {
        return _n('Git plugin source', 'Git plugin sources', $nb, 'gitplugins');
    }

    /** Localised menu title for the plugin's Administration entry. */
    public static function getMenuName(): string
    {
        return __('Git Plugin Installer', 'gitplugins');
    }

    /** Tabler icon class for the menu/breadcrumb. */
    public static function getIcon(): string
    {
        return 'ti ti-git-branch';
    }

    /** Build the plugin's menu entry (title, page, icon, sub-links). */
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
        if (str_contains($host, 'forgejo')) {
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
