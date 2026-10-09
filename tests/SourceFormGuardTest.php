<?php
/**
 * M-1 — object-level access on the source editor and the install queue.
 * The real front/source.form.php and front/install.php run in the harness
 * against a session that holds the plugin right but acts on entity 1 only; a
 * source of entity 2 must be refused before anything is written.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/harness/Harness.php';

final class SourceFormGuardTest extends TestCase
{
    /**
     * Run $script as a POST/GET with $request, a source #5 living in $rowEntity,
     * and a session acting on entity 1 (viewing $viewEntities).
     */
    private static function hit(string $script, string $method, array $request, int $rowEntity, ?array $viewEntities = null): array
    {
        $setup = sprintf(
            "const SCRIPT = %s;\nconst METHOD = %s;\nconst REQ = %s;\nconst ROW_ENTITY = %d;\nconst VIEW = %s;\n",
            var_export($script, true),
            var_export($method, true),
            var_export($request, true),
            $rowEntity,
            var_export($viewEntities, true)
        );
        $code = <<<'PHP'
define('PLUGIN_GITPLUGINS_ROOTDOC', '/plugins/gitplugins');
Session::$rights   = ['plugin_gitplugins' => ALLSTANDARDRIGHT];
Session::$entities = [1];
Session::$viewEntities = VIEW;
$GLOBALS['DB']->tables[PluginGitpluginsSource::getTable()] = [[
    'id' => 5, 'entities_id' => ROW_ENTITY, 'name' => 'foo', 'url' => 'https://github.com/o/foo',
    'host' => 'github.com', 'provider' => 'github', 'plugin_key' => 'foo', 'ref_policy' => 'latest_tag',
    'ref' => null, 'is_active' => 1, 'credential' => null,
]];
$_SERVER['REQUEST_METHOD'] = METHOD;
if (METHOD === 'POST') { $_POST = REQ; } else { $_GET = REQ; }
$r = gp_run_script(SCRIPT);
$writes = array_values(array_filter($GLOBALS['DB']->log, static fn (array $e): bool => $e[0] !== 'query'));
echo json_encode(['outcome' => $r['outcome'], 'detail' => $r['detail'], 'writes' => count($writes)]), "\n";
PHP;

        return GitpluginsHarness::run($setup . $code);
    }

    public function testCrossEntityRemovalDenied(): void
    {
        $r = self::hit('front/source.form.php', 'POST', ['id' => '5', 'delete' => '1'], 2);
        self::assertSame('denied', $r['outcome']);
        self::assertSame(0, $r['writes']);
    }

    public function testCrossEntityEditDenied(): void
    {
        $r = self::hit('front/source.form.php', 'POST', ['id' => '5', 'name' => 'x', 'url' => 'https://github.com/o/foo', 'plugin_key' => 'foo'], 2);
        self::assertSame('denied', $r['outcome']);
        self::assertSame(0, $r['writes']);
    }

    public function testCrossEntityViewDenied(): void
    {
        $r = self::hit('front/source.form.php', 'GET', ['id' => '5'], 2);
        self::assertSame('denied', $r['outcome']);
    }

    /** Control: the same removal inside the session's entity goes through. */
    public function testSameEntityRemovalAllowed(): void
    {
        $r = self::hit('front/source.form.php', 'POST', ['id' => '5', 'delete' => '1'], 1);
        self::assertSame('redirect', $r['outcome']);
        self::assertSame(1, $r['writes']);
    }

    /** A source the session can see (parent entity) but not act on cannot be queued. */
    public function testInstallQueueOfNonUpdatableSourceDenied(): void
    {
        $r = self::hit('front/install.php', 'POST', ['id' => '5'], 2, [1, 2]);
        self::assertSame('denied', $r['outcome']);
        self::assertSame(0, $r['writes']);
    }
}
