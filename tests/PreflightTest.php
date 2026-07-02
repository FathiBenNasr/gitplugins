<?php
/**
 * Pure tests for the environment preflight (R6): GLPI/PHP version bounds and
 * required-extension checks. All runtime facts are injected, so no GLPI/PHP
 * introspection is needed.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/preflight.class.php';

final class PreflightTest extends TestCase
{
    public function testAllSatisfiedIsOk(): void
    {
        $r = PluginGitpluginsPreflight::check(
            ['glpi_min' => '11.0', 'glpi_max' => '11.9', 'php_min' => '8.2', 'extensions' => ['zip', 'gd']],
            '11.0.5',
            '8.3.1',
            ['zip', 'gd', 'json']
        );
        self::assertTrue($r['ok']);
        self::assertSame([], $r['blockers']);
    }

    public function testGlpiBelowMinBlocks(): void
    {
        $r = PluginGitpluginsPreflight::check(
            ['glpi_min' => '11.0'],
            '10.0.9',
            '8.3.0',
            []
        );
        self::assertFalse($r['ok']);
        self::assertStringContainsString('GLPI', $r['blockers'][0]);
    }

    public function testPhpBelowMinBlocks(): void
    {
        $r = PluginGitpluginsPreflight::check(
            ['php_min' => '8.2'],
            '11.0.0',
            '8.1.20',
            []
        );
        self::assertFalse($r['ok']);
        self::assertStringContainsString('PHP', $r['blockers'][0]);
    }

    public function testMissingExtensionBlocks(): void
    {
        $r = PluginGitpluginsPreflight::check(
            ['extensions' => ['snmp', 'zip']],
            '11.0.0',
            '8.3.0',
            ['zip', 'json']
        );
        self::assertFalse($r['ok']);
        self::assertStringContainsString('snmp', $r['blockers'][0]);
    }

    public function testOverGlpiMaxIsWarningNotBlocker(): void
    {
        $r = PluginGitpluginsPreflight::check(
            ['glpi_min' => '10.0', 'glpi_max' => '11.0'],
            '11.5.0',
            '8.3.0',
            []
        );
        self::assertTrue($r['ok']);          // over the tested ceiling is allowed
        self::assertStringContainsString('maximum', $r['warnings'][0]);
    }

    public function testKnownPluginHeuristicAddsExtensions(): void
    {
        $req = PluginGitpluginsPreflight::requirementsFor([], 'glpiinventory');
        self::assertContains('snmp', $req['extensions']);
    }
}
