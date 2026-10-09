<?php
/**
 * L-8 — the commit the admin confirmed is the commit the cron installs.
 * Pure decisions in-process; the cron wiring (cronApplyUpdates) runs in the
 * harness with the network-bound installer replaced by a recorder.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!class_exists('CommonGLPI')) {
    class CommonGLPI {}
}

require_once __DIR__ . '/harness/Harness.php';
require_once __DIR__ . '/../inc/version.class.php';
require_once __DIR__ . '/../inc/refresolver.class.php';
require_once __DIR__ . '/../inc/installer.class.php';
require_once __DIR__ . '/../inc/updatecheck.class.php';

final class InstallerPinningTest extends TestCase
{
    private const SHA_REVIEWED = '1111111111111111111111111111111111111111';
    private const SHA_MOVED    = '2222222222222222222222222222222222222222';

    public function testConfirmMustMatchTheReviewedTarget(): void
    {
        $resolved = ['ref' => 'main', 'sha' => self::SHA_REVIEWED];
        self::assertTrue(PluginGitpluginsInstaller::confirmMatches('main', self::SHA_REVIEWED, $resolved));
        self::assertTrue(PluginGitpluginsInstaller::confirmMatches('main', strtoupper(self::SHA_REVIEWED), $resolved));
        // The branch moved between the confirm screen and the POST.
        self::assertFalse(PluginGitpluginsInstaller::confirmMatches('main', self::SHA_REVIEWED, ['ref' => 'main', 'sha' => self::SHA_MOVED]));
        // A newer tag appeared.
        self::assertFalse(PluginGitpluginsInstaller::confirmMatches('v1.2.0', '', ['ref' => 'v1.3.0', 'sha' => '']));
        // A forged POST without the reviewed values does not match either.
        self::assertFalse(PluginGitpluginsInstaller::confirmMatches('', '', $resolved));
    }

    public function testPinnedJobFetchesTheConfirmedShaNotTheBranch(): void
    {
        $row = ['pending_ref' => 'main', 'pending_sha' => self::SHA_REVIEWED];
        $t   = PluginGitpluginsUpdatecheck::pinnedTarget($row, ['ref' => 'main', 'sha' => self::SHA_MOVED], 'track_branch');
        self::assertSame(['ref' => self::SHA_REVIEWED, 'sha' => self::SHA_REVIEWED], $t);

        // Release assets are fetched by tag; the pinned tag is kept.
        $t = PluginGitpluginsUpdatecheck::pinnedTarget(['pending_ref' => 'v2.0.0', 'pending_sha' => ''], ['ref' => 'v2.1.0'], 'release');
        self::assertSame('v2.0.0', $t['ref']);

        // Legacy unpinned rows still follow the resolver.
        $t = PluginGitpluginsUpdatecheck::pinnedTarget([], ['ref' => 'v3.0.0', 'sha' => ''], 'latest_tag');
        self::assertSame('v3.0.0', $t['ref']);
        self::assertFalse(PluginGitpluginsUpdatecheck::hasPin(['pending_ref' => '', 'pending_sha' => null]));
    }

    public function testReleasePolicyDowngradeBlockedAfterFetch(): void
    {
        $setup = "<?php\ndefine('PLUGIN_FOO_VERSION', '1.4.0');\ndefine('PLUGIN_FOO_MIN_GLPI', '11.0.0');\n";
        self::assertSame('1.4.0', PluginGitpluginsInstaller::versionFromSetup($setup));
        self::assertSame('', PluginGitpluginsInstaller::versionFromSetup("<?php\n// nothing declared\n"));
        // What run() decides on the staged tree: 2.0.0 installed, 1.4.0 downloaded.
        self::assertSame('blocked_downgrade', PluginGitpluginsInstaller::decideAction('2.0.0', PluginGitpluginsInstaller::versionFromSetup($setup), false));
    }

    /** The cron hands the reviewed SHA to the installer, never the moved branch head. */
    public function testCronInstallsConfirmedShaNotBranchHead(): void
    {
        $setup = sprintf("const SHA = %s;\n", var_export(self::SHA_REVIEWED, true));
        $code  = <<<'PHP'
// The network-bound installer is replaced by a recorder (declared before autoload).
final class PluginGitpluginsInstaller
{
    public static array $runs = [];

    public static function run(array $source, string $ref, string $sha = ''): bool
    {
        self::$runs[] = [$ref, $sha];

        return true;
    }
}
Session::$rights = ['plugin_gitplugins' => ALLSTANDARDRIGHT];
$GLOBALS['DB']->tables[PluginGitpluginsSource::getTable()] = [[
    'id' => 5, 'entities_id' => 0, 'url' => 'https://github.com/o/foo', 'provider' => 'github',
    'plugin_key' => 'foo', 'ref_policy' => 'track_branch', 'ref' => 'main', 'is_active' => 1,
]];
$GLOBALS['DB']->tables['glpi_plugin_gitplugins_installs'] = [[
    'id' => 1, 'plugin_key' => 'foo', 'plugin_gitplugins_sources_id' => 5,
    'pending_action' => 'install', 'pending_ref' => 'main', 'pending_sha' => SHA,
]];
PluginGitpluginsUpdatecheck::cronApplyUpdates(new CronTask());
echo json_encode(PluginGitpluginsInstaller::$runs), "\n";
PHP;
        self::assertSame([[self::SHA_REVIEWED, self::SHA_REVIEWED]], GitpluginsHarness::run($setup . $code));
    }
}
