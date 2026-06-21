<?php
/**
 * Git Plugin Installer — discover update sources declared by installed plugins.
 *
 * Scans the locally-installed plugins, reads each one's plugin.xml, and parses
 * its <gitupdate> declaration (via the PURE PluginGitpluginsManifest). For every
 * plugin that declares a source it produces a row describing the declared
 * repo/ref/provider, the installed version, and whether a managed
 * PluginGitpluginsSource already exists for that plugin key.
 *
 * I/O ONLY here (filesystem read of plugin.xml + READ of glpi_plugins / our
 * sources table). The parsing is pure and lives in the manifest class so it is
 * unit-tested without DB/FS (lesson #12). NO outbound network calls happen here
 * — discovery reads local files only; the SSRF-guarded fetch happens later, on
 * an explicit install action, through the existing installer/cron path.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsDiscovery
{
    /**
     * Decide, from a single plugin's manifest + managed-source presence, the
     * source-status for the discovery UI. PURE (no I/O) so it is unit-testable.
     *
     *  - 'marketplace' : the plugin lives under GLPI's own marketplace directory,
     *                    so it is managed by GLPI's marketplace updater — gitplugins
     *                    defers entirely and offers NO actions (read-only). Wins over
     *                    everything else: we must never fight the marketplace updater.
     *  - 'managed'  : a gitplugins source already exists for this plugin key
     *                 (offer Check update / Reinstall) — takes priority
     *  - 'declared' : the plugin declares a usable <gitupdate> but is not yet
     *                 managed (offer Check update / Add source, prefilled)
     *  - 'none'     : no usable <gitupdate> declaration (offer a bare Add source)
     *
     * The marketplace decision is passed in as a plain bool (resolved by the I/O
     * helper isMarketplacePlugin()) so this stays pure and unit-testable — no
     * globals/constants are touched here (lesson #12).
     *
     * @param array|null $manifest         result of PluginGitpluginsManifest::parseXml()
     * @param bool       $hasManagedSource whether a managed source row exists
     * @param bool       $isMarketplace    whether the plugin dir is under the
     *                                      GLPI marketplace directory
     */
    public static function decideState(?array $manifest, bool $hasManagedSource, bool $isMarketplace = false): string
    {
        // Marketplace-managed plugins are off-limits to gitplugins (read-only).
        if ($isMarketplace) {
            return 'marketplace';
        }
        if ($hasManagedSource) {
            return 'managed';
        }
        if ($manifest === null || ($manifest['repo'] ?? '') === '') {
            return 'none';
        }

        return 'declared';
    }

    /**
     * PURE: decide whether a resolved plugin directory sits under the GLPI
     * marketplace directory rather than the manual plugins/ directory. Caller
     * resolves the three paths (via Plugin::getPhpDir / GLPI_MARKETPLACE_DIR /
     * GLPI_ROOT.'/plugins') and feeds them in, so this is testable without GLPI.
     *
     * Compares normalised absolute prefixes: a dir is "marketplace" when it is the
     * marketplace dir itself or a child of it. A plugins-dir match takes precedence
     * (a box could nest, but the manual plugins/ dir is what gitplugins manages).
     *
     * @param string $resolvedDir    the plugin's real on-disk directory ('' = unknown)
     * @param string $marketplaceDir GLPI marketplace dir ('' = not defined)
     * @param string $pluginsDir     GLPI manual plugins dir ('' = not defined)
     */
    public static function isMarketplaceDir(string $resolvedDir, string $marketplaceDir, string $pluginsDir): bool
    {
        $norm = static fn (string $p): string => rtrim(str_replace('\\', '/', trim($p)), '/');
        $dir  = $norm($resolvedDir);
        $mkt  = $norm($marketplaceDir);
        $plg  = $norm($pluginsDir);
        if ($dir === '' || $mkt === '') {
            return false;
        }
        $under = static fn (string $child, string $parent): bool =>
            $parent !== '' && ($child === $parent || str_starts_with($child . '/', $parent . '/'));

        // If it is under the manual plugins/ dir, gitplugins manages it (not mkt).
        if ($under($dir, $plg)) {
            return false;
        }

        return $under($dir, $mkt);
    }

    /**
     * I/O: is an installed plugin managed by GLPI's marketplace (i.e. its real
     * directory is under GLPI_MARKETPLACE_DIR)? Resolves the real path via
     * Plugin::getPhpDir($key) when available, else our own dirFor(), then defers
     * the decision to the pure isMarketplaceDir(). Degrades to false where there
     * is no GLPI core (this dev tree) — nothing is treated as marketplace-managed.
     */
    public static function isMarketplacePlugin(string $key, string $fallbackDir = ''): bool
    {
        if (!defined('GLPI_MARKETPLACE_DIR')) {
            return false; // no marketplace concept available → manage normally
        }
        $marketplaceDir = (string) GLPI_MARKETPLACE_DIR;
        $pluginsDir     = defined('GLPI_ROOT') ? GLPI_ROOT . '/plugins' : '';
        if (!@is_dir($marketplaceDir)) {
            return false;
        }

        // Prefer GLPI's own real-path resolver; fall back to a known directory.
        $dir = '';
        if (class_exists('Plugin') && method_exists('Plugin', 'getPhpDir')) {
            try {
                $dir = (string) \Plugin::getPhpDir($key, true);
            } catch (\Throwable $e) {
                $dir = '';
            }
        }
        if ($dir === '' || !@is_dir($dir)) {
            $dir = $fallbackDir !== '' ? $fallbackDir : self::dirFor($key);
        }

        return self::isMarketplaceDir($dir, $marketplaceDir, $pluginsDir);
    }

    /**
     * The base directories where GLPI keeps plugins. On a live box these are
     * GLPI_ROOT/plugins and GLPI_ROOT/marketplace; guarded with is_dir so the
     * scan degrades safely in a dev tree with no GLPI core.
     *
     * @return string[]
     */
    public static function pluginBaseDirs(): array
    {
        $dirs = [];
        if (defined('GLPI_ROOT')) {
            $dirs[] = GLPI_ROOT . '/plugins';
            if (defined('GLPI_MARKETPLACE_DIR')) {
                $dirs[] = GLPI_MARKETPLACE_DIR;
            } else {
                $dirs[] = GLPI_ROOT . '/marketplace';
            }
        } else {
            // Dev fallback: this plugin lives at .../plugins/gitplugins/inc.
            $dirs[] = dirname(__DIR__, 2);
        }

        return array_values(array_unique(array_filter($dirs, 'is_dir')));
    }

    /**
     * Enumerate installed plugin directory keys from GLPI's own data (preferred)
     * with a filesystem fallback. READS glpi_plugins only — never writes core.
     *
     * @return array<int,array{key:string,name:string,version:string,state:int,dir:string}>
     */
    public static function installedPlugins(): array
    {
        $out = [];

        // Preferred: GLPI's Plugin model knows the canonical installed set.
        if (class_exists('Plugin')) {
            try {
                /** @var DBmysql $DB */
                global $DB;
                if (isset($DB) && $DB->tableExists('glpi_plugins')) {
                    foreach ($DB->request(['FROM' => 'glpi_plugins']) as $p) {
                        $key = (string) ($p['directory'] ?? '');
                        if ($key === '') {
                            continue;
                        }
                        $out[$key] = [
                            'key'     => $key,
                            'name'    => (string) ($p['name'] ?? $key),
                            'version' => (string) ($p['version'] ?? ''),
                            'state'   => (int) ($p['state'] ?? 0),
                            'dir'     => self::dirFor($key),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // fall through to FS scan
            }
        }

        // Fallback / supplement: directories on disk that carry a setup.php.
        foreach (self::pluginBaseDirs() as $base) {
            $entries = @scandir($base);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $key) {
                if ($key === '.' || $key === '..' || !preg_match('/^[a-z0-9_]+$/', $key)) {
                    continue;
                }
                $dir = $base . '/' . $key;
                if (!is_dir($dir) || !is_file($dir . '/setup.php') || isset($out[$key])) {
                    continue;
                }
                $out[$key] = [
                    'key'     => $key,
                    'name'    => $key,
                    'version' => '',
                    'state'   => 0,
                    'dir'     => $dir,
                ];
            }
        }

        return array_values($out);
    }

    /**
     * Enumerate EVERY installed plugin with its source status.
     *
     * Unlike before, plugins WITHOUT a <gitupdate> declaration are still listed
     * (state 'none') so the admin can add a source for them — e.g. assetcve,
     * installed without a declaration, now appears with an "Add source" action.
     * A declared <gitupdate> contributes the repo/ref/provider for prefilling.
     *
     * Filesystem-only (reads each plugin.xml locally) + READs glpi_plugins +
     * our sources table — NO outbound network here (lesson #7/#11).
     *
     * @return array<int,array{
     *   key:string,name:string,installed_version:string,
     *   repo:string,ref:string,ref_type:string,provider:string,private:bool,
     *   has_declaration:bool,has_managed_source:bool,managed_source_id:?int,
     *   is_marketplace:bool,state:string
     * }>
     */
    public static function scan(): array
    {
        $managed = self::managedSourceKeys();
        $rows    = [];

        foreach (self::installedPlugins() as $plugin) {
            $key = $plugin['key'];
            // Skip marketplace-managed plugins entirely — GLPI's own marketplace
            // updater handles them; listing them would only clutter the screen.
            if (self::isMarketplacePlugin($key, $plugin['dir'])) {
                continue;
            }
            // gitplugins lists (and can self-update) itself too.
            $xml      = self::readPluginXml($plugin['dir'], $key);
            $manifest = $xml !== null ? PluginGitpluginsManifest::parseXml($xml) : null;

            $managedId  = $managed[$key] ?? null;
            $hasDecl    = $manifest !== null && ($manifest['repo'] ?? '') !== '';
            $isMkt      = false;
            $rows[] = [
                'key'                => $key,
                'name'               => $plugin['name'] !== '' ? $plugin['name'] : $key,
                'installed_version'  => $plugin['version'] !== ''
                    ? $plugin['version']
                    : ($xml !== null ? self::versionFromXml($xml) : ''),
                'repo'               => $hasDecl ? (string) $manifest['repo'] : '',
                'ref'                => $hasDecl ? (string) $manifest['ref'] : '',
                'ref_type'           => $hasDecl ? (string) $manifest['ref_type'] : '',
                'provider'           => $hasDecl ? (string) $manifest['provider'] : '',
                'private'            => $hasDecl ? (bool) $manifest['private'] : false,
                'has_declaration'    => $hasDecl,
                'has_managed_source' => $managedId !== null,
                'managed_source_id'  => $managedId,
                'is_marketplace'     => $isMkt,
                'state'              => self::decideState($manifest, $managedId !== null, $isMkt),
            ];
        }

        usort($rows, static fn ($a, $b): int => strcmp($a['key'], $b['key']));

        return $rows;
    }

    /** Resolve the on-disk directory for a plugin key across the base dirs. */
    private static function dirFor(string $key): string
    {
        foreach (self::pluginBaseDirs() as $base) {
            if (is_dir($base . '/' . $key)) {
                return $base . '/' . $key;
            }
        }

        return '';
    }

    /**
     * Read a plugin's plugin.xml. Filesystem-only (safe): never executes the
     * target's code, never fetches anything. Returns null if absent/unreadable.
     */
    private static function readPluginXml(string $dir, string $key): ?string
    {
        if ($dir === '' || !preg_match('/^[a-z0-9_]+$/', $key)) {
            return null;
        }
        $path = $dir . '/plugin.xml';
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        // Cap the read so a pathological file cannot exhaust memory (ASVS).
        $data = @file_get_contents($path, false, null, 0, 262144);

        return is_string($data) && $data !== '' ? $data : null;
    }

    /** Extract the declared <version><num> from a plugin.xml (best-effort). */
    private static function versionFromXml(string $xml): string
    {
        $prev = libxml_use_internal_errors(true);
        $doc  = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!($doc instanceof \SimpleXMLElement)) {
            return '';
        }
        // <versions><version><num>…</num></version></versions>
        if (isset($doc->versions->version)) {
            foreach ($doc->versions->version as $v) {
                $num = trim((string) ($v->num ?? ''));
                if ($num !== '') {
                    return $num;
                }
            }
        }

        return trim((string) ($doc->version ?? ''));
    }

    /**
     * Map of plugin_key → managed source id (entity-scoped, A01). READ-only over
     * our own sources table.
     *
     * @return array<string,int>
     */
    public static function managedSourceKeys(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $out = [];
        if (!isset($DB) || !$DB->tableExists('glpi_plugin_gitplugins_sources')) {
            return $out;
        }
        $where = getEntitiesRestrictCriteria('glpi_plugin_gitplugins_sources', '', '', true);
        foreach ($DB->request(['FROM' => 'glpi_plugin_gitplugins_sources', 'WHERE' => $where]) as $r) {
            $key = (string) ($r['plugin_key'] ?? '');
            if ($key !== '' && !isset($out[$key])) {
                $out[$key] = (int) $r['id'];
            }
        }

        return $out;
    }
}
