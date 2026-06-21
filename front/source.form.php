<?php
/**
 * Git Plugin Installer — source editor + CRUD handler.
 *
 * CSRF validated framework-side (GLPI 11 CheckCsrfListener); the hidden
 * _glpi_csrf_token field is what it checks. No manual checkCSRF() (lesson #3).
 * The credential is write-only (never echoed), GLPIKey-encrypted, never logged.
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
$src  = new PluginGitpluginsSource();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Session::checkRight('plugin_gitplugins', UPDATE);
    $id = (int) ($_POST['id'] ?? 0);

    if (isset($_POST['delete']) && $id > 0) {
        Session::checkRight('plugin_gitplugins', PURGE);
        // Remove from management only — does NOT uninstall the GLPI plugin.
        $src->delete(['id' => $id], true);
        Session::addMessageAfterRedirect(__('Source removed from management.', 'gitplugins'));
        Html::redirect($root . '/front/source.php');
    }

    $url      = PluginGitpluginsSource::normaliseUrl((string) ($_POST['url'] ?? ''));
    $key      = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string) ($_POST['plugin_key'] ?? '')) ?? '');
    $policy   = (string) ($_POST['ref_policy'] ?? 'latest_tag');
    $allowed  = ['track_branch', 'latest_tag', 'pin_tag', 'pin_sha'];
    $ref      = trim((string) ($_POST['ref'] ?? ''));

    // Server-side validation (A03/ASVS): HTTPS URL, valid key, valid policy/ref.
    $errors = [];
    if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || PluginGitpluginsSource::hostOf($url) === '') {
        $errors[] = __('The repository URL must be an https:// URL.', 'gitplugins');
    }
    if ($key === '') {
        $errors[] = __('A plugin key (lowercase letters, digits, underscore) is required.', 'gitplugins');
    }
    if (!in_array($policy, $allowed, true)) {
        $policy = 'latest_tag';
    }
    if ($policy !== 'latest_tag' && ($ref === '' || !PluginGitpluginsRefResolver::isValidRef($ref))) {
        $errors[] = __('A valid ref (branch, tag or commit SHA) is required for this policy.', 'gitplugins');
    }
    // Host allowlist enforcement at save time (A10 defence in depth).
    $host = PluginGitpluginsSource::hostOf($url);
    if ($host !== '' && !in_array($host, PluginGitpluginsConfig::singleton()->getAllowedHosts(), true)) {
        $errors[] = sprintf(__('Host "%s" is not in the allowed-hosts list (see Configuration).', 'gitplugins'), $host);
    }

    if ($errors) {
        foreach ($errors as $e) {
            Session::addMessageAfterRedirect($e, false, ERROR);
        }
        Html::redirect($root . '/front/source.form.php' . ($id > 0 ? '?id=' . $id : ''));
    }

    $data = [
        'name'       => mb_substr(PluginGitpluginsSource::normaliseUrl((string) ($_POST['name'] ?? '')) ?: $key, 0, 255),
        'url'        => mb_substr($url, 0, 255),
        'host'       => mb_substr($host, 0, 255),
        'provider'   => PluginGitpluginsSource::deriveProvider($url),
        'plugin_key' => mb_substr($key, 0, 64),
        'ref_policy' => $policy,
        'ref'        => $ref !== '' ? mb_substr($ref, 0, 255) : null,
        'is_active'  => isset($_POST['is_active']) ? 1 : 0,
    ];
    // Credential: only (re)write when a non-empty value is submitted; never echo.
    $cred = (string) ($_POST['credential'] ?? '');
    if ($cred !== '') {
        $data['credential'] = PluginGitpluginsSource::encryptCredential($cred);
    } elseif (isset($_POST['clear_credential'])) {
        $data['credential'] = null;
    }

    if ($id > 0 && $src->getFromDB($id)) {
        $data['id'] = $id;
        $src->update($data);
        Session::addMessageAfterRedirect(__('Source saved.', 'gitplugins'));
    } else {
        $data['entities_id'] = (int) ($_SESSION['glpiactive_entity'] ?? 0);
        $id = (int) $src->add($data);
        Session::addMessageAfterRedirect(__('Source created.', 'gitplugins'));
    }
    Html::redirect($root . '/front/source.form.php?id=' . $id);
}

$id     = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0 && $src->getFromDB($id);
$f      = $isEdit ? $src->fields : [
    'name' => '', 'url' => '', 'plugin_key' => '', 'ref_policy' => 'latest_tag', 'ref' => '', 'is_active' => 1, 'credential' => null,
];

Html::header(PluginGitpluginsSource::getMenuName(), $root . '/front/source.form.php', 'config', 'PluginGitpluginsSource');
$csrf   = Session::getNewCSRFToken();
$action = htmlspecialchars($root . '/front/source.form.php');
$policies = [
    'latest_tag'   => __('Latest release tag', 'gitplugins'),
    'track_branch' => __('Track a branch', 'gitplugins'),
    'pin_tag'      => __('Pin to a tag', 'gitplugins'),
    'pin_sha'      => __('Pin to a commit SHA', 'gitplugins'),
];
?>
<div class="container-fluid"><div class="row justify-content-center"><div class="col-lg-8">
<form method="post" action="<?= $action ?>" class="card mt-3">
  <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <div class="card-header"><h3 class="card-title mb-0"><?= htmlspecialchars($isEdit ? __('Edit source', 'gitplugins') : __('New source', 'gitplugins')) ?></h3></div>
  <div class="card-body">
    <div class="mb-3">
      <label class="form-label"><?= htmlspecialchars(__('Display name', 'gitplugins')) ?></label>
      <input type="text" class="form-control" name="name" maxlength="255" value="<?= htmlspecialchars((string) $f['name']) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label"><?= htmlspecialchars(__('Repository URL (https only)', 'gitplugins')) ?></label>
      <input type="url" class="form-control" name="url" maxlength="255" required placeholder="https://github.com/owner/repo" value="<?= htmlspecialchars((string) $f['url']) ?>">
      <div class="form-text"><?= htmlspecialchars(__('Only hosts on the allow-list (Configuration) are accepted. The server fetches a tarball over HTTPS — no git binary is used.', 'gitplugins')) ?></div>
    </div>
    <div class="mb-3">
      <label class="form-label"><?= htmlspecialchars(__('Plugin key (directory name)', 'gitplugins')) ?></label>
      <input type="text" class="form-control" name="plugin_key" maxlength="64" required pattern="[a-z0-9_]+" value="<?= htmlspecialchars((string) $f['plugin_key']) ?>">
    </div>
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label"><?= htmlspecialchars(__('Ref policy', 'gitplugins')) ?></label>
        <select class="form-select" name="ref_policy">
<?php foreach ($policies as $k => $label): ?>
          <option value="<?= htmlspecialchars($k) ?>"<?= ((string) $f['ref_policy'] === $k) ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
<?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label"><?= htmlspecialchars(__('Ref (branch / tag / SHA)', 'gitplugins')) ?></label>
        <input type="text" class="form-control" name="ref" maxlength="255" value="<?= htmlspecialchars((string) ($f['ref'] ?? '')) ?>">
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label"><?= htmlspecialchars(__('Private-repo token (write-only)', 'gitplugins')) ?></label>
      <input type="password" class="form-control" name="credential" autocomplete="new-password" placeholder="<?= $f['credential'] ? htmlspecialchars(__('•••••• (stored — leave blank to keep)', 'gitplugins')) : '' ?>">
<?php if ($f['credential']): ?>
      <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="clear_credential" id="cc"><label class="form-check-label" for="cc"><?= htmlspecialchars(__('Remove the stored token', 'gitplugins')) ?></label></div>
<?php endif; ?>
      <div class="form-text"><?= htmlspecialchars(__('Encrypted with GLPI\'s key, sent only as an Authorization header, never logged or displayed.', 'gitplugins')) ?></div>
    </div>
    <div class="form-check form-switch mb-2">
      <input class="form-check-input" type="checkbox" name="is_active" id="ia"<?= ((int) $f['is_active'] === 1) ? ' checked' : '' ?>>
      <label class="form-check-label" for="ia"><?= htmlspecialchars(__('Active (checked for updates)', 'gitplugins')) ?></label>
    </div>
  </div>
  <div class="card-footer d-flex gap-2">
    <button type="submit" name="save" class="btn btn-primary"><?= htmlspecialchars(__('Save', 'gitplugins')) ?></button>
    <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($root . '/front/source.php') ?>"><?= htmlspecialchars(__('Back', 'gitplugins')) ?></a>
<?php if ($isEdit): ?>
    <button type="submit" name="delete" formnovalidate class="btn btn-outline-danger ms-auto" onclick="return confirm('<?= htmlspecialchars(__('Remove this source from management? The installed plugin is NOT uninstalled.', 'gitplugins')) ?>');"><?= htmlspecialchars(__('Remove', 'gitplugins')) ?></button>
<?php endif; ?>
  </div>
</form>
</div></div></div>
<?php
Html::footer();
