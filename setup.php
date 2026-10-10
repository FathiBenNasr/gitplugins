<?php
/**
 * Git Plugin Installer — GLPI 11 meta-plugin.
 *
 * Install and update other GLPI plugins from a git/HTTPS source (GitHub, GitLab,
 * Gitea, Forgejo …), extending GLPI's native marketplace/plugin pipeline rather
 * than replacing it: we OWN the SSRF-guarded fetch + zip-slip-safe extraction +
 * atomic placement, then drive GLPI core's public Plugin::install()/activate()
 * seam to install the placed plugin exactly like the marketplace does.
 *
 * Standalone and self-contained — writes only to glpi_plugin_gitplugins_*
 * (+ glpi_profilerights for its own right) and READS glpi_plugins.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 * @link https://git.convergent.tn/fbennasr/GLPI-gitplugins
 */

declare(strict_types=1);

use Glpi\Plugin\Hooks;

define('PLUGIN_GITPLUGINS_VERSION', '1.1.3');
// GLPI 11 only — uses the namespaced plugin API; outbound HTTP via ext-curl (inc/httpclient.class.php).
define('PLUGIN_GITPLUGINS_MIN_GLPI', '12.0.0');
define('PLUGIN_GITPLUGINS_MAX_GLPI', '12.99.99');

// Web-accessible plugin root. Resolved at load time so front/ajax URLs are
// correct whether the plugin lives under /plugins/ or /marketplace/.
// (Do NOT use $_SERVER['PHP_SELF'] in forms — GLPI 11's front controller is
// public/index.php and PHP_SELF misroutes POSTs.)
define('PLUGIN_GITPLUGINS_ROOTDOC', ($GLOBALS['CFG_GLPI']['root_doc'] ?? '') . '/plugins/gitplugins');

/**
 * Plugin init — kept cheap (runs on every request). Heavy work (network fetch,
 * extraction, install) lives in CronTask + on-demand front pages, never inline.
 */
function plugin_init_gitplugins(): void
{
    global $PLUGIN_HOOKS;

    // ajax/deploy.php is a machine-to-machine endpoint authenticated by its own
    // HMAC + timestamp + one-time nonce, never by a GLPI session. Without this
    // declaration GLPI 11+ applies STRATEGY_AUTHENTICATED: a target can never
    // reach it, while any logged-in account (self-service included) can. The
    // pattern is anchored at both ends so it covers that one script only, and
    // the path is stateless so a pull never opens a PHP session.
    if (class_exists(\Glpi\Http\Firewall::class)) {
        \Glpi\Http\Firewall::addPluginStrategyForLegacyScripts(
            'gitplugins',
            '#^/ajax/deploy\.php$#',
            \Glpi\Http\Firewall::STRATEGY_NO_CHECK
        );
    }
    if (class_exists(\Glpi\Http\SessionManager::class)) {
        \Glpi\Http\SessionManager::registerPluginStatelessPath('gitplugins', '#^/ajax/deploy\.php$#');
    }

    // GLPIKey-encrypted columns: registering them lets `bin/console
    // security:change_key` re-encrypt them; otherwise a key rotation leaves the
    // repo tokens and target secrets undecryptable.
    $PLUGIN_HOOKS[Hooks::SECURED_FIELDS]['gitplugins'] = [
        'glpi_plugin_gitplugins_sources.credential',
        'glpi_plugin_gitplugins_targets.secret',
    ];

    // Admin menu entry under Setup — highest-privilege capability (installs
    // remote code), so the menu only shows to holders of our right.
    // Direct config link (wrench) on the plugin/marketplace card.
    $PLUGIN_HOOKS['config_page']['gitplugins'] = 'front/config.php';
    if (Session::haveRight('plugin_gitplugins', READ)) {
        $PLUGIN_HOOKS['menu_toadd']['gitplugins'] = ['config' => 'PluginGitpluginsSource'];
    }

    // Update-check + deferred install runner. allowmode = both so it runs under
    // web-cron AND CLI cron; network work stays OUT of the web request.
    // One itemtype, two cron methods (checkUpdates + notifyUpdates). GLPI auto-
    // registers a CronTask per cronXxx method on the listed itemtype; the
    // install hook also Registers them explicitly with their cadences/comments.
    $PLUGIN_HOOKS['cron']['gitplugins'] = [
        'PluginGitpluginsUpdatecheck' => [
            'frequency'    => HOUR_TIMESTAMP,
            'allowmode'    => CronTask::MODE_EXTERNAL,
            'logslifetime' => 30,
        ],
    ];
}

function plugin_version_gitplugins(): array
{
    return [
        'name'         => 'Git Plugin Installer',
        'version'      => PLUGIN_GITPLUGINS_VERSION,
        'author'       => 'Convergent Cloud Computing',
        'license'      => 'GPL-2.0-or-later',
        'homepage'     => 'https://github.com/FathiBenNasr/gitplugins',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_GITPLUGINS_MIN_GLPI,
                'max' => PLUGIN_GITPLUGINS_MAX_GLPI,
            ],
            'php'  => [
                'min' => '8.2.0',
            ],
        ],
    ];
}

/**
 * Core-only on purpose: GLPI runs this before the plugin autoloader, so no
 * plugin class may be referenced here.
 */
function plugin_gitplugins_check_prerequisites(): bool
{
    // WHY echo + htmlspecialchars: Html::displayMessageAfterRedirect() takes a
    // bool (display container), not a message — under strict_types passing the
    // text threw a TypeError instead of reporting the unmet requirement.
    if (version_compare(GLPI_VERSION, PLUGIN_GITPLUGINS_MIN_GLPI, '<')) {
        echo htmlspecialchars(
            sprintf(__('Git Plugin Installer requires GLPI %s or later.', 'gitplugins'), PLUGIN_GITPLUGINS_MIN_GLPI),
            ENT_QUOTES
        );

        return false;
    }
    if (version_compare(PHP_VERSION, '8.2.0', '<')) {
        echo htmlspecialchars(__('Git Plugin Installer requires PHP 8.2 or later.', 'gitplugins'), ENT_QUOTES);

        return false;
    }

    return true;
}

function plugin_gitplugins_check_config($verbose = false): bool
{
    /** @var DBmysql $DB */
    global $DB;

    if (!$DB->tableExists('glpi_plugin_gitplugins_sources')) {
        if ($verbose) {
            // Same TypeError trap as check_prerequisites(): echo the text itself.
            echo htmlspecialchars(
                __('Git Plugin Installer: schema not installed — run the plugin install step.', 'gitplugins'),
                ENT_QUOTES
            );
        }

        return false;
    }

    return true;
}
