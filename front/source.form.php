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
    $allowed  = ['track_branch', 'latest_tag', 'pin_tag', 'pin_sha', 'release'];
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
    // latest_tag and release resolve a ref themselves (latest tag / latest
    // release), so a ref is optional for both; release additionally accepts an
    // explicit tag to pin a specific release. The pinned policies require a ref.
    $refOptional = in_array($policy, ['latest_tag', 'release'], true);
    if (!$refOptional && ($ref === '' || !PluginGitpluginsRefResolver::isValidRef($ref))) {
        $errors[] = __('A valid ref (branch, tag or commit SHA) is required for this policy.', 'gitplugins');
    }
    if ($policy === 'release' && $ref !== '' && !PluginGitpluginsRefResolver::isValidRef($ref)) {
        $errors[] = __('The release tag is not a valid ref.', 'gitplugins');
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

// Prefill for a fresh form linked from the discovery page ("Add source"): the
// plugin key (always) plus the declared URL/provider when one was discovered.
// Sanitised the same way the POST handler sanitises them (defence in depth).
if (!$isEdit) {
    if (isset($_GET['prefill_key'])) {
        $f['plugin_key'] = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string) $_GET['prefill_key']) ?? '');
    }
    if (isset($_GET['prefill_url'])) {
        $pu = PluginGitpluginsSource::normaliseUrl((string) $_GET['prefill_url']);
        if (strtolower((string) parse_url($pu, PHP_URL_SCHEME)) === 'https' && PluginGitpluginsSource::hostOf($pu) !== '') {
            $f['url'] = mb_substr($pu, 0, 255);
        }
    }
    if (isset($_GET['prefill_name']) && $f['name'] === '') {
        $f['name'] = mb_substr(str_replace(["\r", "\n", "\0"], '', (string) $_GET['prefill_name']), 0, 255);
    }
}

Html::header(PluginGitpluginsSource::getMenuName(), $root . '/front/source.form.php', 'config', 'PluginGitpluginsSource');
$csrf   = Session::getNewCSRFToken();
$action = htmlspecialchars($root . '/front/source.form.php');
$policies = [
    'latest_tag'   => __('Latest release tag', 'gitplugins'),
    'release'      => __('Latest built release (.tgz asset)', 'gitplugins'),
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
      <input type="text" class="form-control" id="gp-name" name="name" maxlength="255" value="<?= htmlspecialchars((string) $f['name']) ?>">
    </div>
    <div class="mb-3">
      <label class="form-label"><?= htmlspecialchars(__('Repository URL (https only)', 'gitplugins')) ?></label>
      <div class="input-group">
        <input type="url" class="form-control" id="gp-url" name="url" maxlength="255" required placeholder="https://github.com/owner/repo" value="<?= htmlspecialchars((string) $f['url']) ?>">
        <button type="button" class="btn btn-outline-primary" id="gp-detect"><i class="ti ti-search"></i> <?= htmlspecialchars(__('Detect', 'gitplugins')) ?></button>
      </div>
      <div class="form-text"><?= htmlspecialchars(__('Only hosts on the allow-list (Configuration) are accepted. The server fetches a tarball over HTTPS — no git binary is used.', 'gitplugins')) ?></div>
      <div class="form-text"><?= htmlspecialchars(__('Paste the repository URL and click Detect to auto-fill the fields from the repository\'s plugin.xml.', 'gitplugins')) ?></div>
      <div id="gp-detect-msg" class="mt-1" role="status"></div>
    </div>
    <div class="mb-3">
      <label class="form-label"><?= htmlspecialchars(__('Plugin key (directory name)', 'gitplugins')) ?></label>
      <input type="text" class="form-control" id="gp-key" name="plugin_key" maxlength="64" required pattern="[a-z0-9_]+" value="<?= htmlspecialchars((string) $f['plugin_key']) ?>">
    </div>
    <div class="row">
      <div class="col-md-6 mb-3">
        <label class="form-label"><?= htmlspecialchars(__('Ref policy', 'gitplugins')) ?></label>
        <select class="form-select" id="gp-policy" name="ref_policy">
<?php foreach ($policies as $k => $label): ?>
          <option value="<?= htmlspecialchars($k) ?>"<?= ((string) $f['ref_policy'] === $k) ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
<?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 mb-3">
        <label class="form-label"><?= htmlspecialchars(__('Ref (branch / tag / SHA)', 'gitplugins')) ?></label>
        <input type="text" class="form-control" id="gp-ref" name="ref" maxlength="255" value="<?= htmlspecialchars((string) ($f['ref'] ?? '')) ?>">
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label"><?= htmlspecialchars(__('Private-repo token (write-only)', 'gitplugins')) ?></label>
      <input type="password" class="form-control" id="gp-cred" name="credential" autocomplete="new-password" placeholder="<?= $f['credential'] ? htmlspecialchars(__('•••••• (stored — leave blank to keep)', 'gitplugins')) : '' ?>">
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
// Localised strings + endpoint handed to the Detect script as JSON (so no
// translatable text is hard-coded in JS and nothing is interpolated unescaped).
$gpDetect = [
    'url'        => $root . '/ajax/detect.php',
    'csrf'       => $csrf,
    'detect'     => __('Detect', 'gitplugins'),
    'detecting'  => __('Detecting…', 'gitplugins'),
    'filled'     => __('Detected and pre-filled the fields below. Review, then Save.', 'gitplugins'),
    'noUrl'      => __('Enter the repository URL first.', 'gitplugins'),
    'privateMsg' => __('This repository is declared private — a token is required to fetch and install it.', 'gitplugins'),
    'errors'     => [
        'bad_url'              => __('Enter a valid https:// repository URL.', 'gitplugins'),
        'host_not_allowed'    => __('That host is not on the allowed-hosts list (see Configuration).', 'gitplugins'),
        'unsupported_provider' => __('Auto-detect is not supported for this provider — fill the fields manually.', 'gitplugins'),
        'fetch_failed'        => __('Could not fetch plugin.xml from the repository. Fill the fields manually.', 'gitplugins'),
        'parse_failed'        => __('The repository\'s plugin.xml could not be read. Fill the fields manually.', 'gitplugins'),
        'forbidden'           => __('You are not allowed to run detection.', 'gitplugins'),
        'generic'             => __('Detection failed. Fill the fields manually.', 'gitplugins'),
    ],
];
?>
<script>
(function () {
  var GP = <?= json_encode($gpDetect, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var btn = document.getElementById('gp-detect');
  if (!btn) { return; }
  var msg = document.getElementById('gp-detect-msg');
  function setMsg(text, cls) {
    msg.textContent = text || '';
    msg.className = 'mt-1 small ' + (cls || '');
  }
  function setIfEmpty(id, val) {
    var el = document.getElementById(id);
    if (el && val && el.value.trim() === '') { el.value = val; }
  }
  btn.addEventListener('click', function () {
    var url = (document.getElementById('gp-url').value || '').trim();
    if (url === '') { setMsg(GP.noUrl, 'text-danger'); return; }
    var ref  = (document.getElementById('gp-ref').value || '').trim();
    var cred = (document.getElementById('gp-cred').value || '');
    btn.disabled = true;
    setMsg(GP.detecting, 'text-muted');

    var body = new URLSearchParams();
    body.set('url', url);
    body.set('ref', ref);
    if (cred !== '') { body.set('credential', cred); }

    // Send as a real AJAX request so GLPI 11 validates the CSRF token from the
    // X-Glpi-Csrf-Token header and PRESERVES it (preserve_token). Putting the
    // token in the body instead made GLPI treat this as a normal POST and
    // CONSUME the form's single-use token, breaking the subsequent Save.
    fetch(GP.url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Glpi-Csrf-Token': GP.csrf
      },
      body: body.toString()
    }).then(function (r) { return r.json().catch(function () { return { ok: false, error: 'generic' }; }); })
      .then(function (d) {
        btn.disabled = false;
        if (!d || d.ok !== true) {
          var key = (d && d.error) || 'generic';
          setMsg(GP.errors[key] || GP.errors.generic, 'text-danger');
          return;
        }
        // Prefill only blank fields so an explicit admin entry is never clobbered.
        setIfEmpty('gp-key', d.plugin_key);
        setIfEmpty('gp-name', d.name);
        setIfEmpty('gp-ref', d.ref);
        if (d.ref_policy) {
          var sel = document.getElementById('gp-policy');
          if (sel) { sel.value = d.ref_policy; }
        }
        var out = GP.filled;
        if (d.private === true) {
          var cr = document.getElementById('gp-cred');
          if (cr) { cr.setAttribute('required', 'required'); cr.classList.add('is-invalid'); }
          out += ' ' + GP.privateMsg;
        }
        setMsg(out, d.private === true ? 'text-warning' : 'text-success');
      }).catch(function () {
        btn.disabled = false;
        setMsg(GP.errors.generic, 'text-danger');
      });
  });
})();
</script>
<?php
Html::footer();
