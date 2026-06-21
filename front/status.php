<?php
/**
 * Git Plugin Installer — managed-plugin status (installed vs available).
 *
 * READ-only over glpi_plugins (never writes core); joins our installs rows.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!Session::haveRight('plugin_gitplugins', READ)) {
    Html::displayRightError();
    exit;
}

/** @var DBmysql $DB */
global $DB;

$root = PLUGIN_GITPLUGINS_ROOTDOC;
Html::header(PluginGitpluginsSource::getMenuName(), $root . '/front/status.php', 'config', 'PluginGitpluginsSource');

$sources = PluginGitpluginsSource::activeRows(true);
$installs = [];
foreach ($DB->request(['FROM' => 'glpi_plugin_gitplugins_installs']) as $i) {
    $installs[(int) $i['plugin_gitplugins_sources_id']] = $i;
}
?>
<div class="container-fluid"><div class="row justify-content-center"><div class="col-lg-11">
  <h2 class="mt-3 mb-2"><?= htmlspecialchars(__('Managed plugin status', 'gitplugins')) ?></h2>
  <table class="table table-hover card-table">
    <thead><tr>
      <th><?= htmlspecialchars(__('Plugin key', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Installed', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Available', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Pending', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Last check', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Last result', 'gitplugins')) ?></th>
      <th></th>
    </tr></thead>
    <tbody>
<?php $any = false; foreach ($sources as $sid => $s): $any = true; $i = $installs[(int) $sid] ?? []; ?>
      <tr>
        <td><code><?= htmlspecialchars((string) $s['plugin_key']) ?></code></td>
        <td><?= htmlspecialchars((string) ($i['installed_version'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
        <td><?= htmlspecialchars((string) ($i['available_version'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
        <td><?= ($i['pending_action'] ?? 'none') !== 'none' ? '<span class="badge bg-info">' . htmlspecialchars((string) $i['pending_action']) . '</span>' : '—' ?></td>
        <td><?= htmlspecialchars((string) ($i['last_check_at'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string) ($i['last_result'] ?? 'none')) ?><?= !empty($i['last_error']) ? ' <span class="text-danger" title="' . htmlspecialchars((string) $i['last_error']) . '">!</span>' : '' ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($root . '/front/install.php?id=' . (int) $sid) ?>"><?= htmlspecialchars(__('Install / Update', 'gitplugins')) ?></a></td>
      </tr>
<?php endforeach; ?>
<?php if (!$any): ?>
      <tr><td colspan="7" class="text-center text-muted"><?= htmlspecialchars(__('No managed sources yet.', 'gitplugins')) ?></td></tr>
<?php endif; ?>
    </tbody>
  </table>
</div></div></div>
<?php
Html::footer();
