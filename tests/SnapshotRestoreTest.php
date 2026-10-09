<?php
/**
 * L-63 / M-15 — PluginGitpluginsSnapshot::restore() and the rollback pruning,
 * run for real (gz file on disk, fake $DB) in the out-of-process harness: a
 * restore executes nothing unless the file is one of the plugin's snapshots in
 * GLPI's dump dir AND every statement stays on the plugin's own tables.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/harness/Harness.php';

final class SnapshotRestoreTest extends TestCase
{
    /**
     * Restore $fixture written under $name (in the dump dir, or elsewhere when
     * $inDumpDir is false) for plugin $key; report the result and how many raw
     * statements reached the database.
     */
    private static function restore(string $fixture, string $key, bool $inDumpDir, string $name): array
    {
        $code = <<<'PHP'
$dump = sys_get_temp_dir() . '/gp_dump_' . bin2hex(random_bytes(4));
$elsewhere = sys_get_temp_dir() . '/gp_else_' . bin2hex(random_bytes(4));
mkdir($dump, 0700);
mkdir($elsewhere, 0700);
define('GLPI_DUMP_DIR', $dump);
// GLPI knows the plugin and a sibling whose key extends it.
$GLOBALS['DB']->tables['glpi_plugins'] = [['id' => 1, 'directory' => 'foo'], ['id' => 2, 'directory' => 'foo_x']];
$sql = (string) file_get_contents(GP_PLUGIN_DIR . '/tests/fixtures/' . FIXTURE);
foreach (PluginGitpluginsSnapshot::tablesNamedIn(PluginGitpluginsSnapshot::splitStatements($sql)) as $t) {
    $GLOBALS['DB']->tables[$t] = [];
}
$path = (IN_DUMP ? $dump : $elsewhere) . '/' . NAME;
$gz = gzopen($path, 'wb9');
gzwrite($gz, $sql);
gzclose($gz);
$ok = PluginGitpluginsSnapshot::restore($path, KEY);
$ran = count(array_filter($GLOBALS['DB']->log, static fn (array $e): bool => $e[0] === 'query'));
@unlink($path);
@rmdir($dump);
@rmdir($elsewhere);
echo json_encode(['ok' => $ok, 'ran' => $ran]), "\n";
PHP;
        $defs = sprintf(
            "const FIXTURE = %s;\nconst KEY = %s;\nconst IN_DUMP = %s;\nconst NAME = %s;\n",
            var_export($fixture, true),
            var_export($key, true),
            $inDumpDir ? 'true' : 'false',
            var_export($name, true)
        );

        return GitpluginsHarness::run($defs . $code);
    }

    public function testGenuineSnapshotRestores(): void
    {
        $r = self::restore('snapshot-clean.txt', 'foo', true, 'gitplugins-snap-foo-20261009120000-a1b2c3.sql.gz');
        self::assertTrue($r['ok']);
        self::assertSame(5, $r['ran']);
    }

    public function testTamperedSnapshotExecutesNothing(): void
    {
        foreach (['snapshot-tampered.txt', 'snapshot-smuggled.txt', 'snapshot-sibling.txt', 'snapshot-readcore.txt'] as $fixture) {
            $r = self::restore($fixture, 'foo', true, 'gitplugins-snap-foo-20261009120000-a1b2c3.sql.gz');
            self::assertFalse($r['ok'], $fixture);
            self::assertSame(0, $r['ran'], $fixture . ': not a single statement may run');
        }
    }

    public function testFileOutsideDumpDirOrOfAnotherPluginIsRefused(): void
    {
        $r = self::restore('snapshot-clean.txt', 'foo', false, 'gitplugins-snap-foo-20261009120000-a1b2c3.sql.gz');
        self::assertFalse($r['ok']);
        self::assertSame(0, $r['ran']);

        $r = self::restore('snapshot-clean.txt', 'foo', true, 'gitplugins-snap-bar-20261009120000-a1b2c3.sql.gz');
        self::assertFalse($r['ok']);
        self::assertSame(0, $r['ran']);
    }

    /** M-15: pruning the oldest snapshot must not unlink a file a newer one still uses. */
    public function testPruneNeverDeletesPathStillReferenced(): void
    {
        $code = <<<'PHP'
$dir = sys_get_temp_dir() . '/gp_prune_' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$shared = $dir . '/gitplugins-snap-foo.sql.gz';          // pre-1.0.4 shared dump
$zip1 = $dir . '/foo-1.zip';
$zip2 = $dir . '/foo-2.zip';
foreach ([$shared, $zip1, $zip2] as $f) {
    file_put_contents($f, 'x');
}
$t = 'glpi_plugin_gitplugins_snapshots';
$GLOBALS['DB']->tables[$t] = [
    ['id' => 1, 'plugin_key' => 'foo', 'files_archive_path' => $zip1, 'db_dump_path' => $shared],
    ['id' => 2, 'plugin_key' => 'foo', 'files_archive_path' => $zip2, 'db_dump_path' => $shared],
];
PluginGitpluginsRollback::prune('foo', 1);
$out = ['zip1' => is_file($zip1), 'zip2' => is_file($zip2), 'shared' => is_file($shared), 'rows' => count($GLOBALS['DB']->tables[$t])];
foreach ([$shared, $zip1, $zip2] as $f) {
    @unlink($f);
}
@rmdir($dir);
echo json_encode($out), "\n";
PHP;
        $r = GitpluginsHarness::run($code);
        self::assertFalse($r['zip1'], 'the pruned snapshot\'s own backup goes');
        self::assertTrue($r['zip2']);
        self::assertTrue($r['shared'], 'the dump still referenced by snapshot 2 stays');
        self::assertSame(1, $r['rows']);
    }
}
