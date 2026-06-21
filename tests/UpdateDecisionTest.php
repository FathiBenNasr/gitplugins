<?php
/**
 * Pure tests for FIX 1 / FEATURE 2/3 decision logic in PluginGitpluginsUpdatecheck:
 *  - isUpdateAvailable()  : flag an update ONLY when the resolved remote differs
 *    from the installed one (SHA for track_branch/pin_sha, version for tag/release).
 *  - availSignature() / shouldNotifyRow() : digest anti-spam (re-notify only when
 *    the available set changes).
 *  - buildDigest() : CR/LF-safe, lists each plugin installed → available.
 *
 * No GLPI bootstrap: stub CommonGLPI + the minimal __()/_n() the methods touch.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!class_exists('CommonGLPI')) {
    class CommonGLPI {}
}
if (!function_exists('__')) {
    function __($s, $d = '')
    {
        return $s;
    }
}
if (!function_exists('_n')) {
    function _n($s, $p, $n, $d = '')
    {
        return $n > 1 ? $p : $s;
    }
}

require_once __DIR__ . '/../inc/version.class.php';
require_once __DIR__ . '/../inc/updatecheck.class.php';

final class UpdateDecisionTest extends TestCase
{
    // ---- track_branch: SHA drives the decision ----
    public function testBranchUnchangedShaIsNoUpdate(): void
    {
        // same HEAD sha → branch hasn't moved → NO update (fixes the re-queue loop)
        self::assertFalse(PluginGitpluginsUpdatecheck::isUpdateAvailable(
            '1.0.0', 'abc123', '1.0.0', 'abc123', 'track_branch'
        ));
    }

    public function testBranchMovedShaIsUpdate(): void
    {
        self::assertTrue(PluginGitpluginsUpdatecheck::isUpdateAvailable(
            '1.0.0', 'abc123', '1.0.0', 'def456', 'track_branch'
        ));
    }

    public function testShaComparisonIsCaseInsensitive(): void
    {
        self::assertFalse(PluginGitpluginsUpdatecheck::isUpdateAvailable(
            '', 'ABC123', '', 'abc123', 'track_branch'
        ));
    }

    // ---- latest_tag / release: version drives the decision ----
    public function testNewerTagIsUpdate(): void
    {
        self::assertTrue(PluginGitpluginsUpdatecheck::isUpdateAvailable(
            '1.0.0', '', 'v1.1.0', '', 'latest_tag'
        ));
    }

    public function testSameTagIsNoUpdate(): void
    {
        self::assertFalse(PluginGitpluginsUpdatecheck::isUpdateAvailable(
            '1.2.3', '', 'v1.2.3', '', 'latest_tag'
        ));
    }

    public function testUnknownRemoteIsNoUpdate(): void
    {
        // neither version nor sha known → never claim an update
        self::assertFalse(PluginGitpluginsUpdatecheck::isUpdateAvailable(
            '1.2.3', '', '', '', 'latest_tag'
        ));
    }

    public function testNothingInstalledKnownRemoteIsUpdate(): void
    {
        self::assertTrue(PluginGitpluginsUpdatecheck::isUpdateAvailable(
            '', '', 'v1.0.0', '', 'latest_tag'
        ));
    }

    public function testKnownAvailShaNeverInstalledIsUpdate(): void
    {
        self::assertTrue(PluginGitpluginsUpdatecheck::isUpdateAvailable(
            '', '', '', 'abc123', 'pin_sha'
        ));
    }

    // ---- digest anti-spam ----
    public function testAvailSignaturePrefersSha(): void
    {
        self::assertSame('abc', PluginGitpluginsUpdatecheck::availSignature(
            ['available_sha' => 'ABC', 'available_version' => '1.0.0']
        ));
        self::assertSame('1.0.0', PluginGitpluginsUpdatecheck::availSignature(
            ['available_sha' => '', 'available_version' => '1.0.0']
        ));
    }

    public function testShouldNotifyOnlyWhenSetChanged(): void
    {
        // flagged + never notified → notify
        self::assertTrue(PluginGitpluginsUpdatecheck::shouldNotifyRow(
            ['update_available' => 1, 'available_version' => '1.1.0', 'last_notified_sha' => '']
        ));
        // flagged + already notified same version → silent (anti-spam)
        self::assertFalse(PluginGitpluginsUpdatecheck::shouldNotifyRow(
            ['update_available' => 1, 'available_version' => '1.1.0', 'last_notified_sha' => '1.1.0']
        ));
        // not flagged → never notify
        self::assertFalse(PluginGitpluginsUpdatecheck::shouldNotifyRow(
            ['update_available' => 0, 'available_version' => '1.1.0', 'last_notified_sha' => '']
        ));
        // flagged + new version since last notify → notify again
        self::assertTrue(PluginGitpluginsUpdatecheck::shouldNotifyRow(
            ['update_available' => 1, 'available_version' => '1.2.0', 'last_notified_sha' => '1.1.0']
        ));
    }

    // ---- digest body ----
    public function testBuildDigestListsPluginsAndIsCrlfSafe(): void
    {
        [$subject, $body] = PluginGitpluginsUpdatecheck::buildDigest([
            ['plugin_key' => 'comm', 'installed_version' => '1.0.0', 'available_version' => '1.1.0', 'repo' => 'https://git.example/comm'],
            // injection attempt in a field must be stripped from the body
            ['plugin_key' => "evil\r\nBcc: x@y", 'installed_version' => '', 'available_version' => 'v2', 'repo' => ''],
        ]);
        self::assertStringContainsString('2', $subject); // plural count text
        self::assertStringContainsString('comm: 1.0.0 → 1.1.0', $body);
        self::assertStringContainsString('https://git.example/comm', $body);
        // no raw CR smuggled through a field value (CR/LF stripped per field)
        self::assertStringNotContainsString("\r", $body);
        // the injected "Bcc:" text is collapsed onto the plugin's own line, not a
        // standalone header line, because the CR/LF between them was stripped.
        foreach (explode("\n", $body) as $line) {
            self::assertFalse(str_starts_with($line, 'Bcc:'));
        }
    }
}
