<?php
/**
 * Git Plugin Installer — configuration page (allowlist, caps, cadence).
 *
 * CSRF validated framework-side (GLPI 11 CheckCsrfListener) — no manual call.
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

$root    = PLUGIN_GITPLUGINS_ROOTDOC;
$pageUrl = $root . '/front/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    PluginGitpluginsConfig::singleton()->saveFields($_POST);
    Session::addMessageAfterRedirect(__('Configuration saved.', 'gitplugins'));
    Html::redirect($pageUrl);
}

Html::header(PluginGitpluginsSource::getMenuName(), $pageUrl, 'config', 'PluginGitpluginsSource');

$config = PluginGitpluginsConfig::singleton();
$csrf   = Session::getNewCSRFToken();
?>
<div class="container-fluid"><div class="row justify-content-center"><div class="col-lg-7">
<form method="post" action="<?= htmlspecialchars($pageUrl) ?>" class="card mt-3">
  <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
  <div class="card-header"><h3 class="card-title mb-0"><?= htmlspecialchars(__('Git Plugin Installer configuration', 'gitplugins')) ?></h3></div>
  <div class="card-body">
    <div class="mb-3">
      <label class="form-label"><?= htmlspecialchars(__('Allowed hosts (one per line)', 'gitplugins')) ?></label>
      <textarea class="form-control" name="allowed_hosts" rows="4"><?= htmlspecialchars(implode("\n", $config->getAllowedHosts())) ?></textarea>
      <div class="form-text"><?= htmlspecialchars(__('SSRF guard: the server will only fetch from these exact hosts. Add codeload.github.com for GitHub. An empty list keeps the safe defaults.', 'gitplugins')) ?></div>
    </div>
    <div class="row">
      <div class="col-md-4 mb-3">
        <label class="form-label"><?= htmlspecialchars(__('Max download size (MB)', 'gitplugins')) ?></label>
        <input type="number" class="form-control" name="max_download_mb" min="1" max="500" value="<?= (int) $config->getMaxDownloadMb() ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label"><?= htmlspecialchars(__('Fetch timeout (seconds)', 'gitplugins')) ?></label>
        <input type="number" class="form-control" name="fetch_timeout_seconds" min="5" max="300" value="<?= (int) $config->getFetchTimeoutSeconds() ?>">
      </div>
      <div class="col-md-4 mb-3">
        <label class="form-label"><?= htmlspecialchars(__('Update-check frequency (minutes)', 'gitplugins')) ?></label>
        <input type="number" class="form-control" name="check_frequency_minutes" min="5" max="40320" value="<?= (int) $config->getCheckFrequencyMinutes() ?>">
      </div>
    </div>
    <div class="form-check form-switch mb-2">
      <input class="form-check-input" type="checkbox" name="allow_auto_install" id="aai"<?= $config->allowAutoInstall() ? ' checked' : '' ?>>
      <label class="form-check-label" for="aai"><?= htmlspecialchars(__('Allow automatic (unattended) install on update — leave OFF for confirm-before-install', 'gitplugins')) ?></label>
    </div>
    <div class="form-check form-switch mb-2">
      <input class="form-check-input" type="checkbox" name="allow_downgrade" id="adg"<?= $config->allowDowngrade() ? ' checked' : '' ?>>
      <label class="form-check-label" for="adg"><?= htmlspecialchars(__('Allow downgrading to an older version (not recommended)', 'gitplugins')) ?></label>
    </div>
    <hr>
    <div class="form-check form-switch mb-2">
      <input class="form-check-input" type="checkbox" name="notify_updates" id="nu"<?= $config->notifyUpdates() ? ' checked' : '' ?>>
      <label class="form-check-label" for="nu"><?= htmlspecialchars(__('Email a digest when managed plugins have updates available', 'gitplugins')) ?></label>
    </div>
    <div class="mb-3">
      <label class="form-label"><?= htmlspecialchars(__('Digest recipient (optional)', 'gitplugins')) ?></label>
      <input type="email" class="form-control" name="notify_recipient" value="<?= htmlspecialchars($config->getNotifyRecipient()) ?>" placeholder="<?= htmlspecialchars(__('Defaults to Super-Admin users / GLPI admin email', 'gitplugins')) ?>">
      <div class="form-text"><?= htmlspecialchars(__('Leave blank to email all active Super-Admin users (or the GLPI admin email).', 'gitplugins')) ?></div>
    </div>
    <hr>
    <div class="form-check form-switch mb-2">
      <input class="form-check-input" type="checkbox" name="allow_local_sources" id="als"<?= $config->allowLocalSources() ? ' checked' : '' ?>>
      <label class="form-check-label" for="als"><?= htmlspecialchars(__('Allow LOCAL/dev filesystem sources — reads the server filesystem; keep OFF on hosted/multi-tenant installs', 'gitplugins')) ?></label>
    </div>
    <div class="mb-3">
      <label class="form-label"><?= htmlspecialchars(__('Local source roots (one absolute path per line)', 'gitplugins')) ?></label>
      <textarea class="form-control" name="local_source_roots" rows="3" placeholder="/srv/glpi-plugins"><?= htmlspecialchars(implode("\n", $config->getLocalSourceRoots())) ?></textarea>
      <div class="form-text"><?= htmlspecialchars(__('A local source path must sit at or under one of these roots. Empty = local sources cannot resolve any path.', 'gitplugins')) ?></div>
    </div>
  </div>
  <div class="card-footer">
    <button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('Save', 'gitplugins')) ?></button>
  </div>
</form>
</div></div></div>
<?php
Html::footer();
