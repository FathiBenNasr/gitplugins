<?php
/**
 * Pure tests for the DB snapshot scope (R8): owned-table enumeration must match
 * ONLY the target plugin's own prefixed tables (never core, never a sibling),
 * and the size-budget gate. No DB — table list + sizes are injected.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/snapshot.class.php';

final class SnapshotTest extends TestCase
{
    public function testOwnedTablesMatchOnlyOwnPrefix(): void
    {
        $all = [
            'glpi_plugins',
            'glpi_computers',
            'glpi_plugin_gitplugins',
            'glpi_plugin_gitplugins_sources',
            'glpi_plugin_gitplugins_installs',
            'glpi_plugin_gitpluginsX_evil',   // different plugin, must NOT match
            'glpi_plugin_other_config',
        ];
        $owned = PluginGitpluginsSnapshot::ownedTables($all, 'gitplugins');
        self::assertContains('glpi_plugin_gitplugins', $owned);
        self::assertContains('glpi_plugin_gitplugins_sources', $owned);
        self::assertContains('glpi_plugin_gitplugins_installs', $owned);
        self::assertStringNotContainsString('gitpluginsX', implode('|', $owned));
        self::assertStringNotContainsString('other', implode('|', $owned));
        self::assertStringNotContainsString('glpi_computers', implode('|', $owned));
    }

    public function testInvalidKeyMatchesNothing(): void
    {
        self::assertSame([], PluginGitpluginsSnapshot::ownedTables(['glpi_plugin_x'], '../evil'));
        self::assertSame([], PluginGitpluginsSnapshot::ownedTables(['glpi_plugin_x'], ''));
    }

    /** M-15: two snapshots of the same plugin never share a dump file. */
    public function testDumpFilenamesAreUniquePerSnapshot(): void
    {
        $a = PluginGitpluginsSnapshot::dumpFilename('foo', '20261009120000', 'a1b2c3d4e5f6');
        $b = PluginGitpluginsSnapshot::dumpFilename('foo', '20261009120000', '0f1e2d3c4b5a');
        $c = PluginGitpluginsSnapshot::dumpFilename('foo', '20261010120000', 'a1b2c3d4e5f6');
        self::assertTrue($a !== $b && $a !== $c && $b !== $c, 'each snapshot owns its file');
        foreach ([$a, $b, $c] as $name) {
            self::assertTrue(str_starts_with($name, PluginGitpluginsSnapshot::dumpPrefix('foo')));
            self::assertTrue(str_ends_with($name, '.sql.gz'));
        }
        // No path separator or traversal can come from the key or the stamp.
        $evil = PluginGitpluginsSnapshot::dumpFilename('../../etc', '../1', '/x');
        self::assertStringNotContainsString('/', $evil);
        self::assertStringNotContainsString('..', $evil);
    }

    /** L-63: a dump carrying a statement on a core table is refused as a whole. */
    public function testRestoreRejectsStatementOutsideOwnedTables(): void
    {
        $clean = PluginGitpluginsSnapshot::splitStatements((string) file_get_contents(__DIR__ . '/fixtures/snapshot-clean.txt'));
        $owned = PluginGitpluginsSnapshot::ownedTables(PluginGitpluginsSnapshot::tablesNamedIn($clean), 'foo');
        self::assertSame(1, count($owned));
        self::assertTrue(PluginGitpluginsSnapshot::statementsAreOwned($clean, $owned), 'a genuine dump restores');

        foreach (['snapshot-tampered.txt', 'snapshot-smuggled.txt'] as $fixture) {
            $stmts = PluginGitpluginsSnapshot::splitStatements((string) file_get_contents(__DIR__ . '/fixtures/' . $fixture));
            $ownedHere = PluginGitpluginsSnapshot::ownedTables(PluginGitpluginsSnapshot::tablesNamedIn($stmts), 'foo');
            self::assertFalse(PluginGitpluginsSnapshot::statementsAreOwned($stmts, $ownedHere), $fixture);
        }
        self::assertFalse(PluginGitpluginsSnapshot::statementsAreOwned([], $owned), 'empty dump is not a restore');
    }

    /** A table of an installed sibling plugin `foo_x` is never captured as `foo`'s. */
    public function testOwnedTablesSiblingPrefix(): void
    {
        $all = ['glpi_plugin_foo', 'glpi_plugin_foo_items', 'glpi_plugin_foo_x', 'glpi_plugin_foo_x_items'];
        $owned = PluginGitpluginsSnapshot::ownedTables($all, 'foo', ['foo', 'foo_x', 'other']);
        self::assertSame(['glpi_plugin_foo', 'glpi_plugin_foo_items'], $owned);
        // Without the sibling installed, the prefix rule is unchanged.
        self::assertSame(4, count(PluginGitpluginsSnapshot::ownedTables($all, 'foo', ['foo'])));

        $stmts = PluginGitpluginsSnapshot::splitStatements((string) file_get_contents(__DIR__ . '/fixtures/snapshot-sibling.txt'));
        $ownedHere = PluginGitpluginsSnapshot::ownedTables(PluginGitpluginsSnapshot::tablesNamedIn($stmts), 'foo', ['foo', 'foo_x']);
        self::assertFalse(PluginGitpluginsSnapshot::statementsAreOwned($stmts, $ownedHere));
    }

    public function testIsInsideDir(): void
    {
        self::assertTrue(PluginGitpluginsSnapshot::isInsideDir('/var/lib/glpi/_dumps/a.sql.gz', '/var/lib/glpi/_dumps'));
        self::assertFalse(PluginGitpluginsSnapshot::isInsideDir('/var/lib/glpi/_dumps/sub/a.sql.gz', '/var/lib/glpi/_dumps'));
        self::assertFalse(PluginGitpluginsSnapshot::isInsideDir('/tmp/a.sql.gz', '/var/lib/glpi/_dumps'));
        self::assertFalse(PluginGitpluginsSnapshot::isInsideDir('/a.sql.gz', '/'));
    }

    public function testBudgetGate(): void
    {
        self::assertTrue(PluginGitpluginsSnapshot::withinBudget(1024 * 1024, 2));   // 1MB <= 2MB
        self::assertFalse(PluginGitpluginsSnapshot::withinBudget(3 * 1024 * 1024, 2)); // 3MB > 2MB
        self::assertTrue(PluginGitpluginsSnapshot::withinBudget(999_999_999, 0));   // 0 cap = unlimited
    }

    public function testSplitStatementsDropsCommentsAndBlanks(): void
    {
        $stmts = PluginGitpluginsSnapshot::splitStatements("-- header\nDROP TABLE a;\nINSERT INTO a VALUES (1);\n");
        self::assertSame(['DROP TABLE a', 'INSERT INTO a VALUES (1)'], $stmts);
    }
}
