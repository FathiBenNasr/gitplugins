<?php
/**
 * Git Plugin Installer — one-click version rollback handler (Phase 2).
 *
 * Restores a retained pre-update snapshot (files + owned tables) for a managed
 * plugin, then re-registers + verifies it. Highest-privilege action (it swaps
 * live plugin code), so it requires plugin_gitplugins UPDATE. CSRF is validated
 * framework-side (GLPI 11 listener) via the hidden token — no manual checkCSRF.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkRight('plugin_gitplugins', UPDATE);

$root = PLUGIN_GITPLUGINS_ROOTDOC;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::redirect($root . '/front/status.php');
}

$snapshotId = (int) ($_POST['snapshot_id'] ?? 0);
if ($snapshotId <= 0) {
    Session::addMessageAfterRedirect(__('No snapshot selected.', 'gitplugins'), false, ERROR);
    Html::redirect($root . '/front/status.php');
}

$pluginsBase = PluginGitpluginsInstaller::pluginsDir();
if (PluginGitpluginsRollback::to($snapshotId, $pluginsBase)) {
    Session::addMessageAfterRedirect(__('Rolled back to the selected snapshot.', 'gitplugins'));
} else {
    Session::addMessageAfterRedirect(__('Rollback failed — the plugin was left as-is (see the log).', 'gitplugins'), false, ERROR);
}
Html::redirect($root . '/front/status.php');
