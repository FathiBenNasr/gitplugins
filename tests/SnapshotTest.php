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
