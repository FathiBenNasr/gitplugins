<?php
/**
 * Pure tests for the install/update/skip/downgrade-refusal decision. No GLPI
 * bootstrap (decideAction touches only the pure version comparer).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/version.class.php';
require_once __DIR__ . '/../inc/refresolver.class.php';

// Pull in only decideAction without booting GLPI: define a minimal stub for the
// classes installer.class.php references at top-level (none beyond the pure two
// required above — decideAction is self-contained).
require_once __DIR__ . '/../inc/installer.class.php';

final class InstallerDecisionTest extends TestCase
{
    public function testFreshInstall(): void
    {
        self::assertSame('install', PluginGitpluginsInstaller::decideAction('', '1.0.0'));
    }

    public function testUpdateWhenNewer(): void
    {
        self::assertSame('update', PluginGitpluginsInstaller::decideAction('1.0.0', 'v1.1.0'));
    }

    public function testSkipWhenSame(): void
    {
        self::assertSame('skip', PluginGitpluginsInstaller::decideAction('1.2.3', 'v1.2.3'));
    }

    public function testSkipWhenAvailableUnknown(): void
    {
        self::assertSame('skip', PluginGitpluginsInstaller::decideAction('1.2.3', ''));
    }

    public function testBlocksDowngradeUnlessForced(): void
    {
        self::assertSame('blocked_downgrade', PluginGitpluginsInstaller::decideAction('2.0.0', '1.0.0'));
        self::assertSame('update', PluginGitpluginsInstaller::decideAction('2.0.0', '1.0.0', true));
    }
}
