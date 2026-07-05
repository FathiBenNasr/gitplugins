<?php
/**
 * Pure tests for the neutralised-backup helpers: filename sanitisation (a
 * backup name must never contain a path separator) and the carry-over decision
 * (preserve vendor/ across an update only when the new release lacks it). No
 * GLPI bootstrap / FS / zip — only the pure static methods are exercised.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/backup.class.php';

final class BackupTest extends TestCase
{
    public function testFilenameIsSafeAndInert(): void
    {
        $name = PluginGitpluginsBackup::backupFilename('assetreport', 'v0.5.0', '1700000000', 'abcd12');
        self::assertSame('assetreport-v0.5.0-1700000000-abcd12.zip', $name);
        // Inert extension, no path separators.
        self::assertStringContainsString('.zip', $name);
        self::assertStringNotContainsString('/', $name);
        self::assertStringNotContainsString('\\', $name);
    }

    public function testFilenameNeutralisesTraversalAndSeparators(): void
    {
        $name = PluginGitpluginsBackup::backupFilename('../../etc', '1.0/../x', 'a/b', 'r\\n');
        self::assertStringNotContainsString('/', $name);
        self::assertStringNotContainsString('\\', $name);
        self::assertStringNotContainsString('..', $name);
    }

    public function testSanitiseLabelCollapsesUnsafeChars(): void
    {
        self::assertSame('a_b', PluginGitpluginsBackup::sanitiseLabel('a/b'));
        self::assertSame('x', PluginGitpluginsBackup::sanitiseLabel('/////'));
        self::assertSame('v1.2.3', PluginGitpluginsBackup::sanitiseLabel('V1.2.3'));
    }

    public function testCarryOverKeepsVendorWhenNewReleaseLacksIt(): void
    {
        $carry = PluginGitpluginsBackup::carryOverList(
            ['vendor', 'node_modules'],
            ['setup.php', 'vendor', 'inc'],          // old tree has a built vendor/
            ['setup.php', 'inc']                     // new release ships none
        );
        self::assertSame(['vendor'], $carry);
    }

    public function testCarryOverYieldsToReleaseThatShipsItsOwn(): void
    {
        $carry = PluginGitpluginsBackup::carryOverList(
            ['vendor'],
            ['vendor'],
            ['vendor']                               // new release brings its own → keep release's
        );
        self::assertSame([], $carry);
    }

    public function testCarryOverIgnoresPathLikeNames(): void
    {
        $carry = PluginGitpluginsBackup::carryOverList(
            ['../evil', 'a/b', 'vendor'],
            ['vendor', '../evil', 'a'],
            []
        );
        self::assertSame(['vendor'], $carry);
    }
}
