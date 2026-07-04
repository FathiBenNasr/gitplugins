<?php
/**
 * Git Plugin Installer — bulk update dry-run report (Phase 9).
 *
 * A NON-MUTATING preview: per managed plugin, installed → available, the resolved
 * action, whether a migration is likely, the host preflight verdict, and the
 * known-issue count. Built network-free from the availability the cron already
 * stored. Tick the applicable rows and submit → front/bulk.php queues them for
 * the applyUpdates cron. READ-only over glpi_plugins + our own tables.
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

$root = PLUGIN_GITPLUGINS_ROOTDOC;
Html::header(PluginGitpluginsSource::getMenuName(), $root . '/front/plan.php', 'config', 'PluginGitpluginsSource');

$plan       = PluginGitpluginsPlanner::plan();
$canUpdate  = Session::haveRight('plugin_gitplugins', UPDATE);
$selectable = PluginGitpluginsPlanner::selectableKeys($plan);
$csrf       = Session::getNewCSRFToken();

$badge = static function (string $action): string {
    return [
        'update'            => 'bg-info',
        'install'           => 'bg-primary',
        'skip'              => 'bg-secondary',
        'blocked_downgrade' => 'bg-danger',
    ][$action] ?? 'bg-secondary';
};
?>
<div class="container-fluid"><div class="row justify-content-center"><div class="col-lg-11">
  <h2 class="mt-3 mb-2"><?= htmlspecialchars(__('Bulk update — dry-run', 'gitplugins')) ?></h2>
  <p class="text-muted"><?= htmlspecialchars(__('Preview only. Ticking a row and applying just queues it; the installer cron runs each through the same verify-and-rollback pipeline. Availability comes from the last update check (no live fetch here).', 'gitplugins')) ?></p>
<?php if ($plan === []): ?>
  <div class="alert alert-info"><?= htmlspecialchars(__('No managed sources yet.', 'gitplugins')) ?></div>
<?php else: ?>
<form method="post" action="<?= htmlspecialchars($root . '/front/bulk.php') ?>">
  <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
  <table class="table table-hover card-table">
    <thead><tr>
      <th style="width:2.5rem"><?php if ($canUpdate && $selectable !== []): ?><input type="checkbox" id="gp-all" title="<?= htmlspecialchars(__('Select all applicable', 'gitplugins')) ?>"><?php endif; ?></th>
      <th><?= htmlspecialchars(__('Plugin key', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Installed', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Available', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Action', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Migration', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Preflight', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Known issues', 'gitplugins')) ?></th>
    </tr></thead>
    <tbody>
<?php foreach ($plan as $r): ?>
      <tr>
        <td><?php if ($canUpdate && !empty($r['selectable'])): ?><input class="gp-row" type="checkbox" name="plugin_keys[]" value="<?= htmlspecialchars((string) $r['plugin_key']) ?>"><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
        <td><code><?= htmlspecialchars((string) $r['plugin_key']) ?></code></td>
        <td><?= htmlspecialchars((string) $r['installed']) ?: '<span class="text-muted">—</span>' ?></td>
        <td><?= htmlspecialchars((string) $r['available']) ?: '<span class="text-muted">—</span>' ?></td>
        <td><span class="badge <?= $badge((string) $r['action']) ?>"><?= htmlspecialchars((string) $r['action']) ?></span></td>
        <td><?= !empty($r['needs_migration']) ? '<span class="badge bg-warning">' . htmlspecialchars(__('likely', 'gitplugins')) . '</span>' : '<span class="text-muted">—</span>' ?></td>
        <td>
<?php if (!empty($r['preflight_ok'])): ?>
          <span class="badge bg-success"><?= htmlspecialchars(__('ready', 'gitplugins')) ?></span>
<?php else: ?>
          <span class="badge bg-danger" title="<?= htmlspecialchars(implode("\n", (array) $r['blockers'])) ?>"><?= htmlspecialchars(__('blocked', 'gitplugins')) ?></span>
<?php endif; ?>
<?php if (!empty($r['warnings'])): ?>
          <span class="badge bg-warning" title="<?= htmlspecialchars(implode("\n", (array) $r['warnings'])) ?>"><?= (int) count($r['warnings']) ?></span>
<?php endif; ?>
        </td>
        <td><?= (int) $r['known_issues'] > 0 ? '<span class="badge bg-danger">' . (int) $r['known_issues'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php if ($canUpdate && $selectable !== []): ?>
  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary" onclick="return confirm('<?= htmlspecialchars(__('Queue the selected plugins for update?', 'gitplugins')) ?>');"><i class="ti ti-cloud-download"></i> <?= htmlspecialchars(__('Update selected', 'gitplugins')) ?></button>
    <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($root . '/front/status.php') ?>"><?= htmlspecialchars(__('Back to status', 'gitplugins')) ?></a>
  </div>
<?php else: ?>
  <div class="alert alert-secondary py-2 mb-0"><?= htmlspecialchars(__('Nothing to apply right now — no plugin has an applicable, host-ready update.', 'gitplugins')) ?></div>
<?php endif; ?>
</form>
<script>
(function () {
  var all = document.getElementById('gp-all');
  if (!all) return;
  all.addEventListener('change', function () {
    document.querySelectorAll('.gp-row').forEach(function (c) { c.checked = all.checked; });
  });
})();
</script>
<?php endif; ?>
</div></div></div>
<?php
Html::footer();
