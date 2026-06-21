<?php
/**
 * Git Plugin Installer — discovered installed plugins (self-declared sources).
 *
 * Lists every locally-installed plugin that declares a <gitupdate> source in its
 * own plugin.xml (read locally — no network at render; lesson #7/#11). For each
 * one the admin can:
 *   - "Register as managed source" → create an entity-scoped PluginGitpluginsSource
 *     from the declared repo/ref/provider (POST behind UPDATE right + CSRF token).
 *     A private repo is registered without a credential and flagged so the admin
 *     is sent to source.form.php to add the GLPIKey-encrypted token.
 *   - "Check update / Reinstall" → reuse the EXISTING confirm-before-install flow
 *     (front/install.php), which enqueues the SSRF-guarded fetch+install for cron.
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

// ----- POST: register a discovered plugin as a managed source -----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    Session::checkRight('plugin_gitplugins', UPDATE);

    $key = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string) ($_POST['plugin_key'] ?? '')) ?? '');
    if ($key === '') {
        Session::addMessageAfterRedirect(__('Invalid plugin key.', 'gitplugins'), false, ERROR);
        Html::redirect($root . '/front/discovered.php');
    }

    // Re-derive the declaration SERVER-SIDE from the plugin's own plugin.xml — we
    // never trust a client-submitted repo/ref (A01: prevents pointing a managed
    // source at an arbitrary URL via a forged POST).
    $declared = null;
    foreach (PluginGitpluginsDiscovery::scan() as $row) {
        if ($row['key'] === $key) {
            $declared = $row;
            break;
        }
    }
    if ($declared === null) {
        Session::addMessageAfterRedirect(__('No declared git source found for this plugin.', 'gitplugins'), false, ERROR);
        Html::redirect($root . '/front/discovered.php');
    }
    if ($declared['has_managed_source']) {
        Session::addMessageAfterRedirect(__('This plugin already has a managed source.', 'gitplugins'));
        Html::redirect($root . '/front/discovered.php');
    }
    // A01: never register / manage a marketplace-managed plugin — GLPI owns it.
    if (!empty($declared['is_marketplace'])) {
        Session::addMessageAfterRedirect(__('This plugin is managed by the GLPI marketplace and cannot be managed here.', 'gitplugins'), false, ERROR);
        Html::redirect($root . '/front/discovered.php');
    }

    $url = PluginGitpluginsSource::normaliseUrl($declared['repo']);
    // Enforce the same SSRF/allowlist policy as a hand-entered source (A10).
    $host = PluginGitpluginsSource::hostOf($url);
    if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https' || $host === '') {
        Session::addMessageAfterRedirect(__('The declared repository URL must be an https:// URL.', 'gitplugins'), false, ERROR);
        Html::redirect($root . '/front/discovered.php');
    }
    if (!in_array($host, PluginGitpluginsConfig::singleton()->getAllowedHosts(), true)) {
        Session::addMessageAfterRedirect(
            sprintf(__('Host "%s" is not in the allowed-hosts list (see Configuration).', 'gitplugins'), $host),
            false,
            ERROR
        );
        Html::redirect($root . '/front/discovered.php');
    }

    // Map the declared ref_type → our ref policy.
    $ref      = trim((string) $declared['ref']);
    $refType  = (string) $declared['ref_type'];
    $policy   = match ($refType) {
        'tag'   => 'pin_tag',
        'sha'   => 'pin_sha',
        default => 'track_branch',
    };
    if ($ref === '' || !PluginGitpluginsRefResolver::isValidRef($ref)) {
        // No usable ref → track the host's latest release tag instead of a branch.
        $policy = 'latest_tag';
        $ref    = '';
    }

    $src  = new PluginGitpluginsSource();
    $data = [
        'name'        => mb_substr($declared['name'] ?: $key, 0, 255),
        'url'         => mb_substr($url, 0, 255),
        'host'        => mb_substr($host, 0, 255),
        'provider'    => PluginGitpluginsSource::deriveProvider($url),
        'plugin_key'  => mb_substr($key, 0, 64),
        'ref_policy'  => $policy,
        'ref'         => $ref !== '' ? mb_substr($ref, 0, 255) : null,
        'is_active'   => 1,
        'entities_id' => (int) ($_SESSION['glpiactive_entity'] ?? 0),
        'is_recursive' => (int) ($_SESSION['glpiactive_entity_recursive'] ?? 0),
    ];
    $id = (int) $src->add($data);

    PluginGitpluginsLog::record($id ?: null, 'register', 'ok', 'registered ' . $key, $ref);

    if ($id > 0 && $declared['private']) {
        // Private repo: needs a credential before any fetch will succeed. Send the
        // admin straight to the editor to add the GLPIKey-encrypted token.
        Session::addMessageAfterRedirect(__('Source registered. Add the private-repo token to enable fetching.', 'gitplugins'));
        Html::redirect($root . '/front/source.form.php?id=' . $id);
    }
    Session::addMessageAfterRedirect(__('Source registered as managed.', 'gitplugins'));
    Html::redirect($root . '/front/discovered.php');
}

$discovered = PluginGitpluginsDiscovery::scan();

Html::header(PluginGitpluginsSource::getMenuName(), $root . '/front/discovered.php', 'config', 'PluginGitpluginsSource');
$csrf   = Session::getNewCSRFToken();
$action = htmlspecialchars($root . '/front/discovered.php');
$canUpd = Session::haveRight('plugin_gitplugins', UPDATE);
?>
<div class="container-fluid"><div class="row justify-content-center"><div class="col-lg-11">
  <h2 class="mt-3 mb-2"><?= htmlspecialchars(__('Installed plugins', 'gitplugins')) ?></h2>
  <p class="text-muted"><?= htmlspecialchars(__('Every installed plugin and its git-source status, read from each plugin\'s own plugin.xml. No network request is made here; registering or adding a source only stores it — fetch/install happens later via the installer.', 'gitplugins')) ?></p>
  <table class="table table-hover card-table">
    <thead><tr>
      <th><?= htmlspecialchars(__('Plugin', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Installed', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Source status', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Repository', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Ref', 'gitplugins')) ?></th>
      <th><?= htmlspecialchars(__('Provider', 'gitplugins')) ?></th>
      <th></th>
    </tr></thead>
    <tbody>
<?php
$stateBadges = [
    'marketplace' => '<span class="badge bg-purple">' . htmlspecialchars(__('marketplace', 'gitplugins')) . '</span>',
    'managed'     => '<span class="badge bg-success">' . htmlspecialchars(__('managed', 'gitplugins')) . '</span>',
    'declared'    => '<span class="badge bg-info">' . htmlspecialchars(__('declared', 'gitplugins')) . '</span>',
    'none'        => '<span class="badge bg-secondary">' . htmlspecialchars(__('no source', 'gitplugins')) . '</span>',
];
$any = false; foreach ($discovered as $d): $any = true;
    // Prefilled "Add source" link: always the key; the declared URL/name too.
    $addUrl = $root . '/front/source.form.php?prefill_key=' . rawurlencode($d['key'])
        . '&prefill_name=' . rawurlencode($d['name']);
    if ($d['has_declaration']) {
        $addUrl .= '&prefill_url=' . rawurlencode($d['repo']);
    }
?>
      <tr>
        <td><strong><?= htmlspecialchars($d['name']) ?></strong> <code><?= htmlspecialchars($d['key']) ?></code>
          <?= $d['private'] ? ' <span class="badge bg-warning">' . htmlspecialchars(__('private', 'gitplugins')) . '</span>' : '' ?>
        </td>
        <td><?= htmlspecialchars($d['installed_version']) ?: '<span class="text-muted">—</span>' ?></td>
        <td><?= $stateBadges[$d['state']] ?? htmlspecialchars($d['state']) ?></td>
        <td class="text-break"><?= $d['repo'] !== '' ? htmlspecialchars($d['repo']) : '<span class="text-muted">—</span>' ?></td>
        <td><?= $d['has_declaration']
            ? (htmlspecialchars($d['ref']) ?: '<span class="text-muted">' . htmlspecialchars(__('latest', 'gitplugins')) . '</span>') . ' <span class="text-muted">(' . htmlspecialchars($d['ref_type']) . ')</span>'
            : '<span class="text-muted">—</span>' ?></td>
        <td><?= $d['provider'] !== '' ? htmlspecialchars($d['provider']) : '<span class="text-muted">—</span>' ?></td>
        <td class="text-end">
<?php if ($d['state'] === 'marketplace'): ?>
          <span class="text-muted"><i class="ti ti-lock"></i> <?= htmlspecialchars(__('Managed by the GLPI marketplace', 'gitplugins')) ?></span>
<?php elseif ($d['state'] === 'managed'): ?>
          <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($root . '/front/install.php?id=' . (int) $d['managed_source_id']) ?>"><?= htmlspecialchars(__('Check update / Reinstall', 'gitplugins')) ?></a>
          <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($root . '/front/source.form.php?id=' . (int) $d['managed_source_id']) ?>"><?= htmlspecialchars(__('Edit source', 'gitplugins')) ?></a>
<?php elseif ($canUpd && $d['state'] === 'declared'): ?>
          <form method="post" action="<?= $action ?>" class="d-inline">
            <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="plugin_key" value="<?= htmlspecialchars($d['key']) ?>">
            <button type="submit" name="register" class="btn btn-sm btn-primary"><?= htmlspecialchars(__('Register as managed source', 'gitplugins')) ?></button>
          </form>
          <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($addUrl) ?>"><?= htmlspecialchars(__('Add source', 'gitplugins')) ?></a>
<?php elseif ($canUpd): ?>
          <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars($addUrl) ?>"><?= htmlspecialchars(__('Add source', 'gitplugins')) ?></a>
<?php else: ?>
          <span class="text-muted">—</span>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
<?php if (!$any): ?>
      <tr><td colspan="7" class="text-center text-muted"><?= htmlspecialchars(__('No installed plugins found.', 'gitplugins')) ?></td></tr>
<?php endif; ?>
    </tbody>
  </table>
</div></div></div>
<?php
Html::footer();
