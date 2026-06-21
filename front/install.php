<?php
/**
 * Git Plugin Installer — confirm-before-install screen + enqueue.
 *
 * Installing runs the TARGET plugin's own (arbitrary) install code, so this is a
 * deliberate supply-chain trust boundary: GET shows the source + resolved ref +
 * SHA + an explicit warning; POST (behind UPDATE right + CSRF) only ENQUEUES the
 * job (sets pending_action) — the cron runner performs the network fetch/extract/
 * install next tick, never inline in this web request (#7/#11; resumable).
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
$src  = new PluginGitpluginsSource();
$id   = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0 || !$src->getFromDB($id) || !$src->canViewItem()) {
    Html::displayRightError();
    exit;
}
$source = $src->fields;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkRight('plugin_gitplugins', UPDATE);

    // Resolve installed vs available and refuse a silent downgrade (A04).
    $installed = PluginGitpluginsInstaller::installedVersion((string) $source['plugin_key']);
    $resolved  = PluginGitpluginsUpdatecheck::resolveLatest($source, PluginGitpluginsConfig::singleton());
    $decision  = PluginGitpluginsInstaller::decideAction($installed, (string) ($resolved['version'] ?? ''), false);

    if ($decision === 'blocked_downgrade') {
        Session::addMessageAfterRedirect(__('Refused: the available version is older than the installed one (downgrade not allowed).', 'gitplugins'), false, ERROR);
        Html::redirect($root . '/front/source.php');
    }
    // The release policy resolves its version only at fetch time, so "skip" here
    // would mean an unknown available version — let the admin (re)install/update
    // anyway; the runner fetches the latest release tarball.
    if ((string) ($source['ref_policy'] ?? '') === 'release' && $decision === 'skip') {
        $decision = $installed === '' ? 'install' : 'update';
    }
    if ($decision === 'skip') {
        Session::addMessageAfterRedirect(__('Already up to date — nothing to install.', 'gitplugins'));
        Html::redirect($root . '/front/source.php');
    }

    // Enqueue — the cron runner fetches + installs out of band. Upsert by
    // plugin_key (UNIQUE: one install-state row per managed plugin), recording
    // which source to act from; never a blind insert (no duplicate rows).
    $DB->updateOrInsert('glpi_plugin_gitplugins_installs', [
        'plugin_key'                   => (string) $source['plugin_key'],
        'plugin_gitplugins_sources_id' => $id,
        'pending_action'               => $decision === 'update' ? 'update' : 'install',
        'last_result'                  => 'pending',
        'last_error'                   => null,
    ], ['plugin_key' => (string) $source['plugin_key']]);
    PluginGitpluginsLog::record($id, 'enqueue', 'ok', $decision . ' ' . $source['plugin_key'], (string) ($resolved['ref'] ?? ''));

    Session::addMessageAfterRedirect(__('Queued. The installer cron will fetch and install it on its next run.', 'gitplugins'));
    Html::redirect($root . '/front/status.php');
}

$installed = PluginGitpluginsInstaller::installedVersion((string) $source['plugin_key']);
$resolved  = PluginGitpluginsUpdatecheck::resolveLatest($source, PluginGitpluginsConfig::singleton());

Html::header(PluginGitpluginsSource::getMenuName(), $root . '/front/install.php', 'config', 'PluginGitpluginsSource');
$csrf = Session::getNewCSRFToken();
?>
<div class="container-fluid"><div class="row justify-content-center"><div class="col-lg-7">
<form method="post" action="<?= htmlspecialchars($root . '/front/install.php') ?>" class="card mt-3">
  <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <div class="card-header"><h3 class="card-title mb-0"><?= htmlspecialchars(__('Confirm install / update', 'gitplugins')) ?></h3></div>
  <div class="card-body">
    <div class="alert alert-warning"><i class="ti ti-alert-triangle"></i>
      <?= htmlspecialchars(__('Installing runs the target plugin\'s own install code on this server. Only proceed for a source you trust.', 'gitplugins')) ?>
    </div>
    <dl class="row mb-0">
      <dt class="col-sm-4"><?= htmlspecialchars(__('Plugin key', 'gitplugins')) ?></dt><dd class="col-sm-8"><code><?= htmlspecialchars((string) $source['plugin_key']) ?></code></dd>
      <dt class="col-sm-4"><?= htmlspecialchars(__('Source URL', 'gitplugins')) ?></dt><dd class="col-sm-8"><?= htmlspecialchars((string) $source['url']) ?></dd>
      <dt class="col-sm-4"><?= htmlspecialchars(__('Resolved ref', 'gitplugins')) ?></dt><dd class="col-sm-8"><?= htmlspecialchars((string) ($resolved['ref'] ?? '')) ?: '<span class="text-muted">—</span>' ?></dd>
      <dt class="col-sm-4"><?= htmlspecialchars(__('Commit SHA', 'gitplugins')) ?></dt><dd class="col-sm-8"><?= htmlspecialchars((string) ($resolved['sha'] ?? '')) ?: '<span class="text-muted">' . htmlspecialchars(__('resolved at fetch', 'gitplugins')) . '</span>' ?></dd>
      <dt class="col-sm-4"><?= htmlspecialchars(__('Installed version', 'gitplugins')) ?></dt><dd class="col-sm-8"><?= htmlspecialchars($installed ?: __('not installed', 'gitplugins')) ?></dd>
      <dt class="col-sm-4"><?= htmlspecialchars(__('Available version', 'gitplugins')) ?></dt><dd class="col-sm-8"><?= htmlspecialchars((string) ($resolved['version'] ?? '')) ?: '<span class="text-muted">—</span>' ?></dd>
    </dl>
  </div>
  <div class="card-footer d-flex gap-2">
    <button type="submit" class="btn btn-primary" onclick="return confirm('<?= htmlspecialchars(__('Queue this install/update?', 'gitplugins')) ?>');"><?= htmlspecialchars(__('Queue install / update', 'gitplugins')) ?></button>
    <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($root . '/front/source.php') ?>"><?= htmlspecialchars(__('Cancel', 'gitplugins')) ?></a>
  </div>
</form>
</div></div></div>
<?php
Html::footer();
