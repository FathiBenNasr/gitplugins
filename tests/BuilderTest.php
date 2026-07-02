<?php
/**
 * Pure tests for the build-step decision (R5): a source tarball needs a build
 * only when opted in, the manifest (composer.json/package.json) is present, and
 * the built dir (vendor/node_modules) is absent. Filesystem facts are injected;
 * the exec itself is not exercised here.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/builder.class.php';

final class BuilderTest extends TestCase
{
    public function testComposerBuildNeededWhenVendorMissing(): void
    {
        self::assertTrue(PluginGitpluginsBuilder::needsComposerBuild(true, true, false));
    }

    public function testComposerBuildSkippedWhenVendorPresent(): void
    {
        self::assertFalse(PluginGitpluginsBuilder::needsComposerBuild(true, true, true));
    }

    public function testComposerBuildSkippedWhenNotOptedIn(): void
    {
        self::assertFalse(PluginGitpluginsBuilder::needsComposerBuild(false, true, false));
    }

    public function testComposerBuildSkippedWithoutComposerJson(): void
    {
        self::assertFalse(PluginGitpluginsBuilder::needsComposerBuild(true, false, false));
    }

    public function testNpmBuildNeedsScriptAndMissingNodeModules(): void
    {
        self::assertTrue(PluginGitpluginsBuilder::needsNpmBuild(true, true, true, false));
        self::assertFalse(PluginGitpluginsBuilder::needsNpmBuild(true, true, false, false)); // no build script
        self::assertFalse(PluginGitpluginsBuilder::needsNpmBuild(true, true, true, true));   // already built
    }
}
