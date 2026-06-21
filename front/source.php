<?php
/**
 * Git Plugin Installer — managed-source list.
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
Html::header(PluginGitpluginsSource::getMenuName(), $root . '/front/source.php', 'config', 'PluginGitpluginsSource');

$rows = $DB->request([
    'FROM'  => 'glpi_plugin_gitplugins_sources',
    'WHERE' => getEntitiesRestrictCriteria('glpi_plugin_gitplugins_sources', '', '', true),
    'ORDER' => 'name',
    'LIMIT' => 200,
]);
?>
<div class="container-fluid"><div class="row justify-content-center"><div class="col-lg-11">
  <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
    <h2 class="mb-0"><?= htmlspecialchars(PluginGitpluginsSource::getTypeName(2)) ?></h2>
<?php if (Session::haveRight('plugin_gitplugins', UPDATE)): ?>
    <a class="btn btn-primary" href="<?= htmlspecialchars($root . '/front/source.form.php') ?>"><i class="ti ti-plus"></i> <?= htmlspecialchars(__('New source', 'gitplugins')) ?></a>
<?php endif; ?>
  </div>
  <table class="table table-hover card-table">
    <thead><tr>
      <th><?= htmlspecialchars(__('Name', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Plugin key', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Provider', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Ref policy', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Active', 'gitplugins')) ?></th>
      <th></th>
    </tr></thead>
    <tbody>
<?php $any = false; foreach ($rows as $s): $any = true; ?>
      <tr>
        <td><a href="<?= htmlspecialchars($root . '/front/source.form.php?id=' . (int) $s['id']) ?>"><?= htmlspecialchars((string) ($s['name'] ?: ('#' . $s['id']))) ?></a></td>
        <td><code><?= htmlspecialchars((string) $s['plugin_key']) ?></code></td>
        <td><?= htmlspecialchars((string) $s['provider']) ?></td>
        <td><?= htmlspecialchars((string) $s['ref_policy']) ?><?= $s['ref'] ? ' <span class="text-muted">(' . htmlspecialchars((string) $s['ref']) . ')</span>' : '' ?></td>
        <td><?= ((int) $s['is_active'] === 1) ? '<span class="badge bg-success">●</span>' : '<span class="badge bg-secondary">—</span>' ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($root . '/front/install.php?id=' . (int) $s['id']) ?>"><?= htmlspecialchars(__('Install / Update', 'gitplugins')) ?></a></td>
      </tr>
<?php endforeach; ?>
<?php if (!$any): ?>
      <tr><td colspan="6" class="text-center text-muted"><?= htmlspecialchars(__('No managed sources yet.', 'gitplugins')) ?></td></tr>
<?php endif; ?>
    </tbody>
  </table>
</div></div></div>
<?php
Html::footer();
