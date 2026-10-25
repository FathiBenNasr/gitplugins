<?php
/**
 * Row-level access on the rollback handler: a snapshot belongs to a source and
 * so to an entity; a session that holds the plugin right but acts on another
 * entity must not be able to restore it. The real front/rollback.php runs in
 * the harness.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/harness/Harness.php';

final class RollbackGuardTest extends TestCase
{
    /** @return array{outcome:string,detail:string,restored:int} */
    private static function hit(int $snapshotId, int $sourceEntity, string $snapshotKey = 'foo', int $snapshotSource = 5): array
    {
        $setup = sprintf(
            "const SNAP = %d;\nconst SRC_ENTITY = %d;\nconst SNAP_KEY = %s;\nconst SNAP_SRC = %d;\n",
            $snapshotId,
            $sourceEntity,
            var_export($snapshotKey, true),
            $snapshotSource
        );
        $code = <<<'PHP'
define('PLUGIN_GITPLUGINS_ROOTDOC', '/plugins/gitplugins');
Session::$rights   = ['plugin_gitplugins' => ALLSTANDARDRIGHT];
Session::$entities = [1];
$GLOBALS['DB']->tables[PluginGitpluginsSource::getTable()] = [[
    'id' => 5, 'entities_id' => SRC_ENTITY, 'name' => 'foo', 'url' => 'https://github.com/o/foo',
    'plugin_key' => 'foo', 'ref_policy' => 'latest_tag', 'is_active' => 1,
]];
$GLOBALS['DB']->tables['glpi_plugin_gitplugins_snapshots'] = [[
    'id' => 9, 'plugin_key' => SNAP_KEY, 'plugin_gitplugins_sources_id' => SNAP_SRC,
    'files_archive_path' => '/nonexistent.zip', 'db_dump_path' => '',
]];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['snapshot_id' => (string) SNAP];
$r = gp_run_script('front/rollback.php');
$reads = array_filter($GLOBALS['DB']->log, static fn (array $e): bool => $e[0] !== 'query');
echo json_encode(['outcome' => $r['outcome'], 'detail' => $r['detail'], 'restored' => count($reads)]), "\n";
PHP;

        return GitpluginsHarness::run($setup . $code);
    }

    public function testSnapshotOfAnotherEntityDenied(): void
    {
        $r = self::hit(9, 2);
        self::assertSame('denied', $r['outcome']);
    }

    public function testSnapshotWithoutLiveSourceDenied(): void
    {
        self::assertSame('denied', self::hit(9, 1, 'foo', 77)['outcome']);
    }

    public function testSnapshotWhoseSourceManagesAnotherPluginDenied(): void
    {
        self::assertSame('denied', self::hit(9, 1, 'other')['outcome']);
    }

    public function testUnknownSnapshotDenied(): void
    {
        self::assertSame('denied', self::hit(123, 1)['outcome']);
    }

    /** Control: same entity goes through to the restore (which fails on the fake path, but is reached). */
    public function testSnapshotOfOwnEntityReachesRestore(): void
    {
        self::assertSame('redirect', self::hit(9, 1)['outcome']);
    }
}
