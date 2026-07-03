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
// Install-state is keyed by plugin_key (one row per managed plugin). The badge +
// summary count below read ONLY the stored update_available flag — no network at
// render (the cron refreshes it; lesson #7/#11).
$installs = [];
foreach ($DB->request(['FROM' => 'glpi_plugin_gitplugins_installs']) as $i) {
    $installs[(string) $i['plugin_key']] = $i;
}
$updateCount = 0;
foreach ($sources as $s) {
    $i = $installs[(string) $s['plugin_key']] ?? [];
    if ((int) ($i['update_available'] ?? 0) === 1) {
        $updateCount++;
    }
}
?>
<div class="container-fluid"><div class="row justify-content-center"><div class="col-lg-11">
  <h2 class="mt-3 mb-2"><?= htmlspecialchars(__('Managed plugin status', 'gitplugins')) ?></h2>
<?php if ($updateCount > 0): ?>
  <div class="alert alert-info"><i class="ti ti-cloud-download"></i>
    <?= htmlspecialchars(sprintf(_n('%d managed plugin has an update available.', '%d managed plugins have updates available.', $updateCount, 'gitplugins'), $updateCount)) ?>
  </div>
<?php endif; ?>
  <table class="table table-hover card-table">
    <thead><tr>
      <th><?= htmlspecialchars(__('Plugin key', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Installed', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Available', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Pending', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Last check', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Last result', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Health', 'gitplugins')) ?></th>
      <th></th>
    </tr></thead>
    <tbody>
<?php $any = false; foreach ($sources as $sid => $s): $any = true; $i = $installs[(string) $s['plugin_key']] ?? []; $hasUpdate = (int) ($i['update_available'] ?? 0) === 1; ?>
      <tr<?= $hasUpdate ? ' class="table-warning"' : '' ?>>
        <td><code><?= htmlspecialchars((string) $s['plugin_key']) ?></code></td>
        <td><?= htmlspecialchars((string) ($i['installed_version'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
        <td><?php if ($hasUpdate): ?><span class="badge bg-info" title="<?= htmlspecialchars(sprintf(__('Update available: %1$s → %2$s', 'gitplugins'), (string) ($i['installed_version'] ?? '?'), (string) (($i['available_version'] ?? '') !== '' ? $i['available_version'] : ($i['available_sha'] ?? '?')))) ?>"><i class="ti ti-cloud-download"></i> <?= htmlspecialchars((string) (($i['available_version'] ?? '') !== '' ? $i['available_version'] : $i['available_sha'] ?? '')) ?></span><?php else: ?><?= htmlspecialchars((string) ($i['available_version'] ?? '')) ?: '<span class="text-muted">—</span>' ?><?php endif; ?></td>
        <td><?= ($i['pending_action'] ?? 'none') !== 'none' ? '<span class="badge bg-info">' . htmlspecialchars((string) $i['pending_action']) . '</span>' : '—' ?></td>
        <td><?= htmlspecialchars((string) ($i['last_check_at'] ?? '')) ?></td>
        <td><?= htmlspecialchars((string) ($i['last_result'] ?? 'none')) ?><?= !empty($i['last_error']) ? ' <span class="text-danger" title="' . htmlspecialchars((string) $i['last_error']) . '">!</span>' : '' ?></td>
<?php
        $health = (string) ($i['health'] ?? 'unknown');
        $hbadge = ['ok' => 'bg-success', 'warn' => 'bg-warning', 'fail' => 'bg-danger', 'unknown' => 'bg-secondary'][$health] ?? 'bg-secondary';
?>
        <td><?php if (($i['health'] ?? '') !== ''): ?><span class="badge <?= $hbadge ?>"<?= !empty($i['health_detail']) ? ' title="' . htmlspecialchars((string) $i['health_detail']) . '"' : '' ?>><?= htmlspecialchars($health) ?></span><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
        <td class="text-end">
          <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($root . '/front/install.php?id=' . (int) $sid) ?>"><?= htmlspecialchars(__('Install / Update', 'gitplugins')) ?></a>
<?php $snaps = PluginGitpluginsRollback::rowsFor((string) $s['plugin_key']); if ($snaps): ?>
          <form method="post" action="<?= htmlspecialchars($root . '/front/rollback.php') ?>" class="d-inline-flex gap-1 align-items-center ms-1" onsubmit="return confirm('<?= htmlspecialchars(__('Roll back this plugin to the selected snapshot? Live plugin code and its tables are replaced.', 'gitplugins')) ?>');">
            <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars(Session::getNewCSRFToken()) ?>">
            <select name="snapshot_id" class="form-select form-select-sm" style="width:auto">
<?php foreach ($snaps as $snap): ?>
              <option value="<?= (int) $snap['id'] ?>"><?= htmlspecialchars((string) ($snap['version'] ?? '?')) ?> · <?= htmlspecialchars((string) ($snap['date_creation'] ?? '')) ?></option>
<?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-warning"><i class="ti ti-arrow-back-up"></i> <?= htmlspecialchars(__('Rollback', 'gitplugins')) ?></button>
          </form>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
<?php if (!$any): ?>
      <tr><td colspan="8" class="text-center text-muted"><?= htmlspecialchars(__('No managed sources yet.', 'gitplugins')) ?></td></tr>
<?php endif; ?>
    </tbody>
  </table>
</div></div></div>
<?php
Html::footer();
