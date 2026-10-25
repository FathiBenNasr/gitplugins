<?php
/**
 * The confirm screen must not call the forge (with the source's token) on a
 * plain GET: a read-only user reloading it would hammer the forge. Only a POST
 * ("review") by a user who may queue the job resolves the ref.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/harness/Harness.php';

final class InstallConfirmNetworkTest extends TestCase
{
    /** @return array{outcome:string,detail:string,calls:int,tokens:int,output:string} */
    private static function hit(string $method, array $request, int $rights): array
    {
        $setup = sprintf(
            "const METHOD = %s;\nconst REQ = %s;\nconst RIGHTS = %d;\n",
            var_export($method, true),
            var_export($request, true),
            $rights
        );
        $code = <<<'PHP'
// Replace the HTTP client BEFORE the autoloader can load the real one: count
// every outbound call and every call that carries the source's token.
final class PluginGitpluginsHttpclient
{
    public static int $calls = 0;
    public static int $tokens = 0;
    public static function get(string $url, array $opts): array
    {
        self::$calls++;
        if (str_contains(json_encode($opts['headers'] ?? []), 'sekrit-token')) {
            self::$tokens++;
        }
        return ['status' => 200, 'body' => '[{"name":"v2.0.0","commit":{"sha":"' . str_repeat('a', 40) . '"}}]'];
    }
}
// Minimal core Plugin stand-in: nothing is installed yet.
final class Plugin
{
    public array $fields = [];
    public function getFromDBbyDir(string $dir): bool { return false; }
}
define('PLUGIN_GITPLUGINS_ROOTDOC', '/plugins/gitplugins');
Session::$rights   = ['plugin_gitplugins' => RIGHTS];
Session::$entities = [1];
$GLOBALS['DB']->tables[PluginGitpluginsSource::getTable()] = [[
    'id' => 5, 'entities_id' => 1, 'name' => 'foo', 'url' => 'https://github.com/o/foo',
    'host' => 'github.com', 'provider' => 'github', 'plugin_key' => 'foo', 'ref_policy' => 'latest_tag',
    'ref' => null, 'is_active' => 1, 'credential' => 'enc:' . base64_encode('sekrit-token'),
]];
$_SERVER['REQUEST_METHOD'] = METHOD;
if (METHOD === 'POST') { $_POST = REQ; } else { $_GET = REQ; }
$r = gp_run_script('front/install.php');
echo json_encode([
    'outcome' => $r['outcome'], 'detail' => $r['detail'], 'calls' => PluginGitpluginsHttpclient::$calls,
    'tokens' => PluginGitpluginsHttpclient::$tokens, 'output' => $r['output'],
]), "\n";
PHP;

        return GitpluginsHarness::run($setup . $code);
    }

    public function testGetByReaderMakesNoOutboundCall(): void
    {
        $r = self::hit('GET', ['id' => '5'], READ_ONLY);
        self::assertSame(0, $r['calls']);
        self::assertSame(0, $r['tokens']);
        self::assertFalse(str_contains($r['output'], 'name="review"'), 'a reader gets no review button');
    }

    public function testGetByWriterMakesNoOutboundCallEither(): void
    {
        $r = self::hit('GET', ['id' => '5'], ALL_RIGHTS);
        self::assertSame(0, $r['calls'], 'GET never reaches the forge');
        self::assertTrue(str_contains($r['output'], 'name="review"'), 'the writer is offered the review step');
    }

    public function testReviewPostByReaderIsDeniedWithoutOutboundCall(): void
    {
        $r = self::hit('POST', ['id' => '5', 'review' => '1'], READ_ONLY);
        self::assertSame('denied', $r['outcome'], $r['detail']);
        self::assertSame(0, $r['calls']);
    }

    /** Control: the review POST by an authorised user does resolve the ref (with the token). */
    public function testReviewPostByWriterResolvesTheRef(): void
    {
        $r = self::hit('POST', ['id' => '5', 'review' => '1'], ALL_RIGHTS);
        self::assertTrue($r['calls'] > 0);
        self::assertTrue($r['tokens'] > 0);
        self::assertTrue(str_contains($r['output'], 'value="v2.0.0"'), 'the confirm screen binds the resolved ref');
    }
}

const READ_ONLY = 1;
const ALL_RIGHTS = 31;
