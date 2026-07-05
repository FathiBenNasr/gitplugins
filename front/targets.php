<?php
/**
 * Git Plugin Installer — deploy targets registry (Phase 3, PULL model).
 *
 * CRUD for the GLPI instances allowed to PULL the signed deploy manifest. Each
 * target has a name and a shared HMAC secret (GLPIKey-encrypted, write-only,
 * never echoed). No inbound install happens here — targets fetch ajax/deploy.php
 * and install through their own pipeline. CSRF validated framework-side.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkRight('plugin_gitplugins', UPDATE);
    $id = (int) ($_POST['id'] ?? 0);

    if (isset($_POST['delete']) && $id > 0) {
        Session::checkRight('plugin_gitplugins', PURGE);
        $DB->delete('glpi_plugin_gitplugins_targets', ['id' => $id]);
        Session::addMessageAfterRedirect(__('Target removed.', 'gitplugins'));
        Html::redirect($root . '/front/targets.php');
    }

    // Name: the identifier the target signs with — restricted to a safe subset.
    $name = strtolower(preg_replace('/[^a-z0-9_.-]/i', '', (string) ($_POST['name'] ?? '')) ?? '');
    $base = str_replace(["\r", "\n", "\0", ' '], '', trim((string) ($_POST['base_url'] ?? '')));
    $errors = [];
    if ($name === '') {
        $errors[] = __('A target name (letters, digits, dot, underscore, hyphen) is required.', 'gitplugins');
    }
    if ($base !== '' && strtolower((string) parse_url($base, PHP_URL_SCHEME)) !== 'https') {
        $errors[] = __('The base URL, if set, must be an https:// URL.', 'gitplugins');
    }

    if ($errors) {
        foreach ($errors as $e) {
            Session::addMessageAfterRedirect($e, false, ERROR);
        }
        Html::redirect($root . '/front/targets.php');
    }

    $data = [
        'name'      => mb_substr($name, 0, 64),
        'base_url'  => mb_substr($base, 0, 255),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'date_mod'  => date('Y-m-d H:i:s'),
    ];
    // Secret: only (re)write when a non-empty value is submitted; never echo.
    $secret = (string) ($_POST['secret'] ?? '');
    if ($secret !== '') {
        $data['secret'] = PluginGitpluginsSource::encryptCredential($secret);
    }

    if ($id > 0) {
        $data['id'] = $id;
        $DB->update('glpi_plugin_gitplugins_targets', $data, ['id' => $id]);
        Session::addMessageAfterRedirect(__('Target saved.', 'gitplugins'));
    } else {
        $data['date_creation'] = date('Y-m-d H:i:s');
        // A brand-new target needs a secret.
        if (empty($data['secret'])) {
            Session::addMessageAfterRedirect(__('A shared secret is required for a new target.', 'gitplugins'), false, ERROR);
            Html::redirect($root . '/front/targets.php');
        }
        $DB->insert('glpi_plugin_gitplugins_targets', $data);
    }
    Html::redirect($root . '/front/targets.php');
}

Html::header(PluginGitpluginsSource::getMenuName(), $root . '/front/targets.php', 'config', 'PluginGitpluginsSource');

$canUpdate = Session::haveRight('plugin_gitplugins', UPDATE);
$csrf      = Session::getNewCSRFToken();
$rows      = [];
foreach ($DB->request(['FROM' => 'glpi_plugin_gitplugins_targets', 'ORDER' => 'name ASC']) as $r) {
    $rows[] = $r;
}
?>
<div class="container-fluid"><div class="row justify-content-center"><div class="col-lg-10">
  <h2 class="mt-3 mb-2"><?= htmlspecialchars(__('Deploy targets', 'gitplugins')) ?></h2>
  <div class="alert alert-info py-2"><i class="ti ti-info-circle"></i>
    <?= htmlspecialchars(__('Pull model: a target GLPI instance authenticates with its shared secret and pulls a signed, SHA-pinned deploy manifest, then installs through its own verified pipeline. This server never installs onto a target.', 'gitplugins')) ?>
    <div class="small mt-1"><?= htmlspecialchars(__('Manifest endpoint:', 'gitplugins')) ?> <code><?= htmlspecialchars($root . '/ajax/deploy.php') ?></code></div>
  </div>
  <table class="table table-hover card-table">
    <thead><tr>
      <th><?= htmlspecialchars(__('Name', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Base URL', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Active', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Last pull', 'gitplugins')) ?></th>
      <th></th>
    </tr></thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr>
        <td><code><?= htmlspecialchars((string) $r['name']) ?></code></td>
        <td><?= htmlspecialchars((string) $r['base_url']) ?: '<span class="text-muted">—</span>' ?></td>
        <td><?= (int) $r['is_active'] === 1 ? '<span class="badge bg-success">' . htmlspecialchars(__('yes', 'gitplugins')) . '</span>' : '<span class="badge bg-secondary">' . htmlspecialchars(__('no', 'gitplugins')) . '</span>' ?></td>
        <td><?= htmlspecialchars((string) ($r['last_pull_at'] ?? '')) ?: '<span class="text-muted">—</span>' ?></td>
        <td class="text-end">
<?php if ($canUpdate): ?>
          <form method="post" action="<?= htmlspecialchars($root . '/front/targets.php') ?>" class="d-inline" onsubmit="return confirm('<?= htmlspecialchars(__('Remove this target?', 'gitplugins')) ?>');">
            <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars(Session::getNewCSRFToken()) ?>">
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button type="submit" name="delete" class="btn btn-sm btn-outline-danger"><i class="ti ti-trash"></i></button>
          </form>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
<?php if ($rows === []): ?>
      <tr><td colspan="5" class="text-center text-muted"><?= htmlspecialchars(__('No deploy targets yet.', 'gitplugins')) ?></td></tr>
<?php endif; ?>
    </tbody>
  </table>
<?php if ($canUpdate): ?>
  <form method="post" action="<?= htmlspecialchars($root . '/front/targets.php') ?>" class="card">
    <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="id" value="0">
    <div class="card-header"><h3 class="card-title mb-0"><?= htmlspecialchars(__('Add a target', 'gitplugins')) ?></h3></div>
    <div class="card-body">
      <div class="row">
        <div class="col-md-4 mb-3">
          <label class="form-label"><?= htmlspecialchars(__('Name', 'gitplugins')) ?></label>
          <input type="text" class="form-control" name="name" maxlength="64" required pattern="[A-Za-z0-9_.-]+" placeholder="prod-servicedesk">
        </div>
        <div class="col-md-8 mb-3">
          <label class="form-label"><?= htmlspecialchars(__('Base URL (informational)', 'gitplugins')) ?></label>
          <input type="url" class="form-control" name="base_url" maxlength="255" placeholder="https://servicedesk.example.tn">
        </div>
      </div>
      <div class="mb-3">
        <label class="form-label"><?= htmlspecialchars(__('Shared secret (write-only)', 'gitplugins')) ?></label>
        <input type="password" class="form-control" name="secret" autocomplete="new-password" required>
        <div class="form-text"><?= htmlspecialchars(__('The HMAC key the target signs its pull requests with. Encrypted with GLPI\'s key, never logged or displayed. Use a long random string and configure the identical value on the target.', 'gitplugins')) ?></div>
      </div>
      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" name="is_active" id="ta" checked>
        <label class="form-check-label" for="ta"><?= htmlspecialchars(__('Active (may pull the manifest)', 'gitplugins')) ?></label>
      </div>
    </div>
    <div class="card-footer"><button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('Add target', 'gitplugins')) ?></button></div>
  </form>
<?php endif; ?>
</div></div></div>
<?php
Html::footer();
