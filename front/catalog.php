<?php
/**
 * Git Plugin Installer — convergent plugin catalog (Phase 10).
 *
 * Browse the curated convergent plugin suite and one-click PRE-FILL a source row
 * (never a silent install): each "Add source" links to the normal create form
 * with prefill params, so the admin still confirms + preflights + installs
 * explicitly. A "Refresh" POST (UPDATE right + framework CSRF) re-fetches the
 * SSRF-allowlisted manifest into the cache. READ-only over glpi_plugins + our own
 * tables at render.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh'])) {
    Session::checkRight('plugin_gitplugins', UPDATE);
    $n = PluginGitpluginsCatalog::refresh();
    if ($n > 0) {
        Session::addMessageAfterRedirect(sprintf(__('Catalog refreshed — %d plugin(s).', 'gitplugins'), $n));
    } else {
        Session::addMessageAfterRedirect(__('Could not refresh the catalog. Check the catalog URL and the host allow-list (Configuration).', 'gitplugins'), false, WARNING);
    }
    Html::redirect($root . '/front/catalog.php');
}

Html::header(PluginGitpluginsSource::getMenuName(), $root . '/front/catalog.php', 'config', 'PluginGitpluginsSource');

$entries   = PluginGitpluginsCatalog::cached();
$canUpdate = Session::haveRight('plugin_gitplugins', UPDATE);
$hasUrl    = PluginGitpluginsConfig::singleton()->getCatalogUrl() !== '';
$csrf      = Session::getNewCSRFToken();

// Which catalog keys are already registered as sources (to badge "managed").
$managed = [];
foreach ($DB->request(['SELECT' => ['plugin_key'], 'FROM' => 'glpi_plugin_gitplugins_sources']) as $r) {
    $managed[(string) $r['plugin_key']] = true;
}
?>
<div class="container-fluid"><div class="row justify-content-center"><div class="col-lg-11">
  <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
    <h2 class="mb-0"><?= htmlspecialchars(__('Plugin catalog', 'gitplugins')) ?></h2>
<?php if ($canUpdate && $hasUrl): ?>
    <form method="post" action="<?= htmlspecialchars($root . '/front/catalog.php') ?>">
      <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <button type="submit" name="refresh" value="1" class="btn btn-outline-primary"><i class="ti ti-refresh"></i> <?= htmlspecialchars(__('Refresh catalog', 'gitplugins')) ?></button>
    </form>
<?php endif; ?>
  </div>
<?php if (!$hasUrl): ?>
  <div class="alert alert-info"><?= htmlspecialchars(__('No catalog URL configured. Set one in Configuration to browse and one-click add plugins from the convergent suite.', 'gitplugins')) ?></div>
<?php elseif ($entries === []): ?>
  <div class="alert alert-warning"><?= htmlspecialchars(__('The catalog is empty. Click Refresh to fetch it from the configured manifest.', 'gitplugins')) ?></div>
<?php else: ?>
  <p class="text-muted"><?= htmlspecialchars(__('Advisory only — "Add source" pre-fills the create form; every install still runs the full confirm + preflight and executes the target plugin\'s own code.', 'gitplugins')) ?></p>
  <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
<?php foreach ($entries as $e):
    $key   = (string) $e['plugin_key'];
    $prefill = $root . '/front/source.form.php?' . http_build_query([
        'prefill_key'  => $key,
        'prefill_url'  => (string) $e['url'],
        'prefill_name' => (string) $e['name'],
    ]);
?>
    <div class="col">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start">
            <h5 class="card-title mb-1"><?= htmlspecialchars((string) $e['name']) ?></h5>
<?php if (isset($managed[$key])): ?>
            <span class="badge bg-success"><?= htmlspecialchars(__('managed', 'gitplugins')) ?></span>
<?php endif; ?>
          </div>
          <div class="mb-2"><code><?= htmlspecialchars($key) ?></code><?php if ((string) $e['category'] !== ''): ?> <span class="badge bg-secondary"><?= htmlspecialchars((string) $e['category']) ?></span><?php endif; ?></div>
<?php if ((string) $e['description'] !== ''): ?>
          <p class="card-text small text-muted"><?= htmlspecialchars((string) $e['description']) ?></p>
<?php endif; ?>
          <div class="small text-muted"><?= htmlspecialchars((string) $e['url']) ?></div>
          <div class="small text-muted"><?= htmlspecialchars(sprintf(__('Recommended policy: %s', 'gitplugins'), (string) $e['ref_policy'])) ?></div>
        </div>
        <div class="card-footer">
<?php if (isset($managed[$key])): ?>
          <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($root . '/front/source.php') ?>"><?= htmlspecialchars(__('Manage', 'gitplugins')) ?></a>
<?php elseif ($canUpdate): ?>
          <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars($prefill) ?>"><i class="ti ti-plus"></i> <?= htmlspecialchars(__('Add source', 'gitplugins')) ?></a>
<?php else: ?>
          <span class="text-muted small"><?= htmlspecialchars(__('Read-only', 'gitplugins')) ?></span>
<?php endif; ?>
        </div>
      </div>
    </div>
<?php endforeach; ?>
  </div>
<?php endif; ?>
</div></div></div>
<?php
Html::footer();
