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
     * Decide, from a single plugin's directory listing + manifest, the action
     * state for the discovery UI. PURE (no I/O) so it is unit-testable.
     *
     *  - 'unmanaged' : a source is declared but not yet registered as managed
     *  - 'managed'   : a managed source already exists for this plugin key
     *  - 'none'      : no usable <gitupdate> declaration
     *
     * @param array|null $manifest result of PluginGitpluginsManifest::parseXml()
     * @param bool       $hasManagedSource whether a managed source row exists
     */
    public static function decideState(?array $manifest, bool $hasManagedSource): string
    {
        if ($manifest === null || ($manifest['repo'] ?? '') === '') {
            return 'none';
        }

        return $hasManagedSource ? 'managed' : 'unmanaged';
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
     * Discover declared update sources across all installed plugins.
     *
     * @return array<int,array{
     *   key:string,name:string,installed_version:string,
     *   repo:string,ref:string,ref_type:string,provider:string,private:bool,
     *   has_managed_source:bool,managed_source_id:?int,state:string
     * }>
     */
    public static function scan(): array
    {
        $managed = self::managedSourceKeys();
        $rows    = [];

        foreach (self::installedPlugins() as $plugin) {
            $key  = $plugin['key'];
            $xml  = self::readPluginXml($plugin['dir'], $key);
            if ($xml === null) {
                continue;
            }
            $manifest = PluginGitpluginsManifest::parseXml($xml);
            if ($manifest === null) {
                continue; // plugin declares no usable source
            }

            $managedId = $managed[$key] ?? null;
            $rows[] = [
                'key'                => $key,
                'name'               => $plugin['name'] !== '' ? $plugin['name'] : $key,
                'installed_version'  => $plugin['version'] !== '' ? $plugin['version'] : self::versionFromXml($xml),
                'repo'               => $manifest['repo'],
                'ref'                => $manifest['ref'],
                'ref_type'           => $manifest['ref_type'],
                'provider'           => $manifest['provider'],
                'private'            => $manifest['private'],
                'has_managed_source' => $managedId !== null,
                'managed_source_id'  => $managedId,
                'state'              => self::decideState($manifest, $managedId !== null),
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
