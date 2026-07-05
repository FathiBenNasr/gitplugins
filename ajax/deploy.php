<?php
/**
 * Git Plugin Installer — deploy manifest endpoint (Phase 3, PULL model).
 *
 * READ-ONLY, machine-to-machine. A registered target GETs this endpoint with a
 * per-target HMAC signature; on success it receives the current SHA-pinned deploy
 * manifest (which plugin to install at exactly which ref), itself HMAC-signed with
 * the target's shared secret so the target can trust the response. This endpoint
 * NEVER installs anything and NEVER runs target-supplied code — it only serves
 * signed data; each target installs through its OWN verified Phase 0 pipeline.
 *
 * Not session-authenticated (no browser): authentication is the mutual HMAC +
 * a freshness window (replay guard). The manifest carries no secrets.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

// The canonical path both ends sign over (fixed, not the raw REQUEST_URI, so a
// reverse proxy rewrite can't desynchronise the two sides).
const GP_DEPLOY_SIGN_PATH = 'gitplugins/ajax/deploy.php';

$fail = static function (): void {
    // Generic 403 — no enumeration signal (unknown target vs bad signature vs
    // stale timestamp all look identical).
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
};

$target    = (string) ($_SERVER['HTTP_X_GP_TARGET'] ?? '');
$timestamp = (string) ($_SERVER['HTTP_X_GP_TIMESTAMP'] ?? '');
$signature = (string) ($_SERVER['HTTP_X_GP_SIGNATURE'] ?? '');
$method    = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($target === '' || $timestamp === '' || $signature === '') {
    $fail();
}

$now    = time();
$secret = PluginGitpluginsDeploy::authenticate($target, $method, GP_DEPLOY_SIGN_PATH, $timestamp, $signature, $now);
if ($secret === '') {
    $fail();
}

// Authenticated → serve the signed, SHA-pinned manifest. Sign the RESPONSE body
// with the same shared secret so the target can verify authenticity end-to-end.
[$body, $sig] = PluginGitpluginsDeploy::exportForTarget($secret, $now);
header('X-Gp-Signature: ' . $sig);
echo $body;
