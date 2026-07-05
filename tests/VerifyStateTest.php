<?php
/**
 * Pure tests for the post-install health decision (isHealthyState): a plugin is
 * only "installed OK" when it is ACTIVE and carries a version — a version bump
 * that deactivates it (state != ACTIVATED) must be caught so the caller rolls
 * back. Plugin::ACTIVATED is passed in, so no GLPI bootstrap is needed.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/version.class.php';
require_once __DIR__ . '/../inc/refresolver.class.php';
require_once __DIR__ . '/../inc/installer.class.php';

final class VerifyStateTest extends TestCase
{
    private const ACTIVATED = 1; // Plugin::ACTIVATED

    public function testActiveAndVersionedIsHealthy(): void
    {
        self::assertTrue(PluginGitpluginsInstaller::isHealthyState(self::ACTIVATED, '0.5.0', self::ACTIVATED));
    }

    public function testDeactivatedIsUnhealthy(): void
    {
        // state 4 (e.g. TOBECONFIGURED) after a version mismatch → rollback.
        self::assertFalse(PluginGitpluginsInstaller::isHealthyState(4, '0.5.0', self::ACTIVATED));
    }

    public function testActiveButNoVersionIsUnhealthy(): void
    {
        self::assertFalse(PluginGitpluginsInstaller::isHealthyState(self::ACTIVATED, '   ', self::ACTIVATED));
    }
}
