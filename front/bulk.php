<?php
/**
 * Git Plugin Installer — bulk apply handler (Phase 9).
 *
 * POST (behind the UPDATE right + framework CSRF) only ENQUEUES the selected
 * plugins for update (pending_action='update'); the applyUpdates cron runs them
 * out of band through the Phase 0 pipeline (never inline in this web request —
 * #7/#11). The selection is re-validated server-side against the current plan
 * (Planner::queueBulk) — a POSTed key that isn't genuinely selectable is ignored.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!Session::haveRight('plugin_gitplugins', UPDATE)) {
    Html::displayRightError();
    exit;
}

$root = PLUGIN_GITPLUGINS_ROOTDOC;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::redirect($root . '/front/plan.php');
}
Session::checkRight('plugin_gitplugins', UPDATE);

$keys = $_POST['plugin_keys'] ?? [];
if (!is_array($keys)) {
    $keys = [];
}
$queued = PluginGitpluginsPlanner::queueBulk(array_map('strval', $keys));

if ($queued > 0) {
    Session::addMessageAfterRedirect(sprintf(
        _n('%d plugin queued for update — the installer cron will apply it.', '%d plugins queued for update — the installer cron will apply them.', $queued, 'gitplugins'),
        $queued
    ));
} else {
    Session::addMessageAfterRedirect(__('Nothing queued — no applicable plugins were selected.', 'gitplugins'), false, WARNING);
}
Html::redirect($root . '/front/status.php');
