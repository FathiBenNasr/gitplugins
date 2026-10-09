<?php
/**
 * L-10 — the anonymous deploy endpoint: a captured signed pull cannot be
 * replayed, a short shared secret is refused, and setup.php declares exactly
 * that one route anonymous + stateless. Runs PluginGitpluginsDeploy::
 * authenticate() and plugin_init_gitplugins() for real in the harness.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/harness/Harness.php';
require_once __DIR__ . '/../inc/deploy.class.php';

final class DeployReplayTest extends TestCase
{
    private const STRONG = '0123456789abcdef0123456789abcdef-strong';

    /**
     * Register target "prod" with $secret, then present each of $requests
     * (nonce, timestamp offset) signed with $secret; returns which ones were
     * authenticated.
     *
     * @param array<int,array{0:string,1:int}> $requests
     * @return bool[]
     */
    private static function pulls(string $secret, array $requests): array
    {
        $setup = sprintf("const SECRET = %s;\nconst REQS = %s;\n", var_export($secret, true), var_export($requests, true));
        $code  = <<<'PHP'
$now = 1_800_000_000;
$db  = $GLOBALS['DB'];
$db->tables[PluginGitpluginsDeploy::TARGETS_TABLE] = [
    ['id' => 3, 'name' => 'prod', 'is_active' => 1, 'secret' => (new GLPIKey())->encrypt(SECRET)],
];
$db->tables[PluginGitpluginsDeploy::NONCE_TABLE] = [];
$db->uniqueKeys[PluginGitpluginsDeploy::NONCE_TABLE] = [['plugin_gitplugins_targets_id', 'nonce']];
$out = [];
foreach (REQS as [$nonce, $offset]) {
    $ts  = (string) ($now + $offset);
    $sig = PluginGitpluginsDeploy::sign(
        PluginGitpluginsDeploy::requestStringToSign('GET', 'gitplugins/ajax/deploy.php', $ts, 'prod', $nonce),
        SECRET
    );
    $out[] = PluginGitpluginsDeploy::authenticate('prod', 'GET', 'gitplugins/ajax/deploy.php', $ts, $nonce, $sig, $now) !== '';
}
echo json_encode($out), "\n";
PHP;

        return GitpluginsHarness::run($setup . $code);
    }

    public function testReplayedNonceRejected(): void
    {
        $r = self::pulls(self::STRONG, [
            ['aaaaaaaaaaaaaaaa1111', 0],
            ['aaaaaaaaaaaaaaaa1111', 10],   // same nonce, still inside the window → replay
            ['bbbbbbbbbbbbbbbb2222', 20],   // fresh nonce → accepted
        ]);
        self::assertSame([true, false, true], $r);
    }

    public function testMissingOrMalformedNonceRejected(): void
    {
        self::assertSame([false, false], self::pulls(self::STRONG, [['', 0], ['short', 0]]));
    }

    public function testShortSecretRefused(): void
    {
        // Correctly signed, fresh, unique — but the stored secret is too weak.
        self::assertSame([false], self::pulls('s3cr3t', [['cccccccccccccccc3333', 0]]));
        self::assertFalse(PluginGitpluginsDeploy::isStrongSecret(str_repeat('x', 31)));
        self::assertTrue(PluginGitpluginsDeploy::isStrongSecret(str_repeat('x', 32)));
    }

    /** The route is declared NO_CHECK + stateless, anchored, and nothing else is. */
    public function testDeployRouteDeclaredNoCheckAndOnlyThatRoute(): void
    {
        $code = <<<'PHP'
require GP_PLUGIN_DIR . '/setup.php';
plugin_init_gitplugins();
echo json_encode([
    'fw'      => \Glpi\Http\Firewall::$strategies,
    'state'   => \Glpi\Http\SessionManager::$stateless,
    'secured' => $GLOBALS['PLUGIN_HOOKS'][\Glpi\Plugin\Hooks::SECURED_FIELDS]['gitplugins'] ?? [],
]), "\n";
PHP;
        $r = GitpluginsHarness::run($code);
        self::assertSame(['gitplugins' => ['#^/ajax/deploy\.php$#' => 'no_check']], $r['fw']);
        self::assertSame(['gitplugins' => ['#^/ajax/deploy\.php$#']], $r['state']);

        $pattern = array_key_first($r['fw']['gitplugins']);
        self::assertSame(1, preg_match($pattern, '/ajax/deploy.php'));
        foreach (['/ajax/deploy.php.bak', '/ajax/deploy.php/x', '/ajax/detect.php', '/front/config.php', '/x/ajax/deploy.php'] as $other) {
            self::assertSame(0, preg_match($pattern, $other), $other);
        }
        self::assertSame(PluginGitpluginsDeploy::ROUTE_PATTERN, $pattern, 'setup.php and the class agree');

        // L-4: the GLPIKey-encrypted columns are registered for key rotation.
        self::assertContains('glpi_plugin_gitplugins_sources.credential', $r['secured']);
        self::assertContains('glpi_plugin_gitplugins_targets.secret', $r['secured']);
    }
}
