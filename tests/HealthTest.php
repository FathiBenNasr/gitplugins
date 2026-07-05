<?php
/**
 * Pure tests for the post-install health verdict mapping (Phase 5): the two
 * optional self-check results (prerequisites, config) → ok|warn|fail|unknown.
 * null means the check function is absent. No I/O — evaluate() is integration.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/health.class.php';

final class HealthTest extends TestCase
{
    public function testBothPassIsOk(): void
    {
        self::assertSame('ok', PluginGitpluginsHealth::classify(true, true));
    }

    public function testEitherFailIsFail(): void
    {
        self::assertSame('fail', PluginGitpluginsHealth::classify(false, true));
        self::assertSame('fail', PluginGitpluginsHealth::classify(true, false));
        self::assertSame('fail', PluginGitpluginsHealth::classify(false, null));
    }

    public function testNoChecksIsUnknown(): void
    {
        self::assertSame('unknown', PluginGitpluginsHealth::classify(null, null));
    }

    public function testOnePresentPassingIsOk(): void
    {
        self::assertSame('ok', PluginGitpluginsHealth::classify(true, null));
        self::assertSame('ok', PluginGitpluginsHealth::classify(null, true));
    }
}
