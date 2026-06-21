<?php
/**
 * Git Plugin Installer — "Detect from URL" endpoint for the Add-source form.
 *
 * Given a repo URL (+ optional ref), this fetches the repository's plugin.xml
 * over HTTPS using the EXISTING SSRF-guarded fetcher and returns the descriptive
 * fields (key/name/version) + any <gitupdate> declaration so the form can
 * prefill itself. The admin then reviews and saves.
 *
 * This is the ONE place gitplugins makes an outbound call on user-supplied input,
 * so it is fenced hard (A01/A10):
 *   - POST only; Session::checkLoginUser() + the plugin_gitplugins right;
 *   - GLPI 11's CheckCsrfListener auto-validates the hidden _glpi_csrf_token
 *     (no manual checkCSRF — lesson #3);
 *   - the URL passes the SAME host-allowlist + SSRF validation as a real install
 *     (assertSafeUrl) before any fetch;
 *   - the credential is used as a bearer header only, never echoed back;
 *   - errors are GENERIC (no enumeration / upstream detail leak).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');

Session::checkLoginUser();
// Detecting fetches remote code metadata → require the same UPDATE right the
// admin needs to actually create a source.
if (!Session::haveRight('plugin_gitplugins', UPDATE)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$url = PluginGitpluginsSource::normaliseUrl((string) ($_POST['url'] ?? ''));
$ref = trim((string) ($_POST['ref'] ?? ''));
// A credential may be supplied for a private repo so detection can read its
// manifest; used only as a bearer header, never returned in the response.
$token = (string) ($_POST['credential'] ?? '');

// (1) Basic shape + provider derivation (pure, no network).
$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
$host   = PluginGitpluginsSource::hostOf($url);
if ($scheme !== 'https' || $host === '') {
    echo json_encode(['ok' => false, 'error' => 'bad_url']);
    exit;
}

$config  = PluginGitpluginsConfig::singleton();
$allowed = $config->getAllowedHosts();

// (2) Host allowlist — fail fast with a clear, specific message (A10).
if (!in_array($host, $allowed, true)) {
    echo json_encode(['ok' => false, 'error' => 'host_not_allowed', 'host' => $host]);
    exit;
}

$provider = PluginGitpluginsSource::deriveProvider($url);
$rawUrls  = PluginGitpluginsRefResolver::rawManifestUrls($provider, $url, $ref);
if ($rawUrls === []) {
    echo json_encode(['ok' => false, 'error' => 'unsupported_provider', 'provider' => $provider]);
    exit;
}

// The raw-content host can differ from the repo host (GitHub → raw.github…).
// Allow it for THIS fetch only if it is also on the configured allowlist; the
// fetcher re-asserts the full SSRF policy regardless.
$xml = null;
foreach ($rawUrls as $candidate) {
    $rawHost = strtolower((string) parse_url($candidate, PHP_URL_HOST));
    if (!in_array($rawHost, $allowed, true)) {
        continue;
    }
    try {
        $xml = PluginGitpluginsFetcher::fetchText(
            $candidate,
            $allowed,
            $token,
            512 * 1024,
            $config->getFetchTimeoutSeconds()
        );
        break; // first 200 wins
    } catch (\Throwable $e) {
        $xml = null; // try the next candidate; never leak upstream detail
    }
}

if ($xml === null) {
    // Could not fetch any candidate plugin.xml. The admin can still fill manually.
    echo json_encode(['ok' => false, 'error' => 'fetch_failed', 'provider' => $provider]);
    exit;
}

$info = PluginGitpluginsManifest::parseInfo($xml);
if ($info === null) {
    echo json_encode(['ok' => false, 'error' => 'parse_failed', 'provider' => $provider]);
    exit;
}

// A <gitupdate><provider> overrides the URL-derived provider; same for ref/type.
$gu          = $info['gitupdate'];
$outProvider = $provider;
$outRef      = $ref;
$outRefType  = '';
$private     = false;
if (is_array($gu)) {
    if (($gu['provider'] ?? '') !== '' && $gu['provider'] !== 'unknown') {
        $outProvider = (string) $gu['provider'];
    }
    if ($outRef === '' && ($gu['ref'] ?? '') !== '') {
        $outRef = (string) $gu['ref'];
    }
    $outRefType = (string) ($gu['ref_type'] ?? '');
    $private    = (bool) ($gu['private'] ?? false);
}

// Map a declared ref_type → our ref policy (mirrors front/discovered.php).
$policy = '';
switch ($outRefType) {
    case 'tag':
        $policy = 'pin_tag';
        break;
    case 'sha':
        $policy = 'pin_sha';
        break;
    case 'branch':
        $policy = 'track_branch';
        break;
}

echo json_encode([
    'ok'         => true,
    'plugin_key' => (string) $info['key'],
    'name'       => (string) $info['name'],
    'version'    => (string) $info['version'],
    'provider'   => $outProvider,
    'ref'        => $outRef,
    'ref_policy' => $policy,
    'private'    => $private,
]);
