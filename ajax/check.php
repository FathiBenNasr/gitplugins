<?php
/**
 * Git Plugin Installer — lightweight "check now" trigger for one source.
 *
 * Does NOT do a heavy fetch in-request (lesson #7/#11): it only flags the source
 * for a refresh and returns the cached availability. The actual tags-API call
 * runs in the cron checker. GET is idempotent → no CSRF token needed (the
 * framework's CheckCsrfListener only guards state-changing POSTs).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');

Session::checkLoginUser();
if (!Session::haveRight('plugin_gitplugins', READ)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

/** @var DBmysql $DB */
global $DB;

$id  = (int) ($_GET['id'] ?? 0);
$src = new PluginGitpluginsSource();
if ($id <= 0 || !$src->getFromDB($id) || !$src->canViewItem()) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

$row = $DB->request([
    'FROM'  => 'glpi_plugin_gitplugins_installs',
    'WHERE' => ['plugin_gitplugins_sources_id' => $id],
    'LIMIT' => 1,
])->current() ?: [];

echo json_encode([
    'plugin_key'        => (string) $src->fields['plugin_key'],
    'installed_version' => (string) ($row['installed_version'] ?? ''),
    'available_version' => (string) ($row['available_version'] ?? ''),
    'last_check_at'     => (string) ($row['last_check_at'] ?? ''),
    'pending_action'    => (string) ($row['pending_action'] ?? 'none'),
]);
