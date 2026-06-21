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
 * @link https://git.convergent.tn/fbennasr/gitplugins
 */

declare(strict_types=1);

use Glpi\Plugin\Hooks;

define('PLUGIN_GITPLUGINS_VERSION', '0.1.0');
// GLPI 11 only — uses the namespaced plugin API + Toolbox::getGuzzleClient().
define('PLUGIN_GITPLUGINS_MIN_GLPI', '11.0.0');
define('PLUGIN_GITPLUGINS_MAX_GLPI', '11.99.99');

// Web-accessible plugin root. Resolved at load time so front/ajax URLs are
// correct whether the plugin lives under /plugins/ or /marketplace/.
// (Do NOT use $_SERVER['PHP_SELF'] in forms — GLPI 11's front controller is
// public/index.php and PHP_SELF misroutes POSTs.)
define('PLUGIN_GITPLUGINS_ROOTDOC', Plugin::getWebDir('gitplugins'));

/**
 * Plugin init — kept cheap (runs on every request). Heavy work (network fetch,
 * extraction, install) lives in CronTask + on-demand front pages, never inline.
 */
function plugin_init_gitplugins(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['gitplugins'] = true;

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
        'homepage'     => 'https://git.convergent.tn/fbennasr/gitplugins',
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

function plugin_gitplugins_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_GITPLUGINS_MIN_GLPI, '<')) {
        echo Html::displayMessageAfterRedirect(
            sprintf(__('Git Plugin Installer requires GLPI %s or later.', 'gitplugins'), PLUGIN_GITPLUGINS_MIN_GLPI),
            ERROR
        );

        return false;
    }
    if (version_compare(PHP_VERSION, '8.2.0', '<')) {
        echo Html::displayMessageAfterRedirect(__('Git Plugin Installer requires PHP 8.2 or later.', 'gitplugins'), ERROR);

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
            echo Html::displayMessageAfterRedirect(
                __('Git Plugin Installer: schema not installed — run the plugin install step.', 'gitplugins'),
                WARNING
            );
        }

        return false;
    }

    return true;
}
