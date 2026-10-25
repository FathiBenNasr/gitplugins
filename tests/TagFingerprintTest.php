<?php
/**
 * A tag (or a release asset) is mutable. What was installed for a tag is
 * fingerprinted — the commit the tag pointed to, the SHA-256 of a release
 * asset — and the same tag later pointing to other content is refused.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/harness/Harness.php';
require_once __DIR__ . '/../inc/installer.class.php';
require_once __DIR__ . '/../inc/refresolver.class.php';
require_once __DIR__ . '/../inc/updatecheck.class.php';

final class TagFingerprintTest extends TestCase
{
    private const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testSameTagOtherCommitIsMoved(): void
    {
        $row = ['installed_tag' => 'v1.4.0', 'installed_sha' => self::A];
        self::assertSame('moved', PluginGitpluginsInstaller::fingerprintVerdict($row, 'v1.4.0', self::B, null));
        self::assertSame('ok', PluginGitpluginsInstaller::fingerprintVerdict($row, 'v1.4.0', strtoupper(self::A), null));
    }

    public function testSameTagUnresolvableCommitFailsClosed(): void
    {
        $row = ['installed_tag' => 'v1.4.0', 'installed_sha' => self::A];
        self::assertSame('unverifiable', PluginGitpluginsInstaller::fingerprintVerdict($row, 'v1.4.0', '', null));
    }

    public function testFirstSightAndOtherTagsAreAllowed(): void
    {
        self::assertSame('ok', PluginGitpluginsInstaller::fingerprintVerdict([], 'v1.4.0', self::B, null));
        $row = ['installed_tag' => 'v1.4.0', 'installed_sha' => self::A];
        self::assertSame('ok', PluginGitpluginsInstaller::fingerprintVerdict($row, 'v1.5.0', self::B, null));
        // A row installed before fingerprints existed has no tag recorded.
        self::assertSame('ok', PluginGitpluginsInstaller::fingerprintVerdict(['installed_sha' => self::A], 'v1.4.0', self::B, null));
    }

    public function testReleaseAssetHashMismatchIsMoved(): void
    {
        $old = hash('sha256', 'old asset');
        $row = ['installed_tag' => 'v2.0.0', 'installed_asset_sha256' => $old];
        self::assertSame('moved', PluginGitpluginsInstaller::fingerprintVerdict($row, 'v2.0.0', null, hash('sha256', 'swapped asset')));
        self::assertSame('ok', PluginGitpluginsInstaller::fingerprintVerdict($row, 'v2.0.0', null, $old));
        self::assertSame('unverifiable', PluginGitpluginsInstaller::fingerprintVerdict($row, 'v2.0.0', null, ''));
    }

    public function testTagCommitShaParsing(): void
    {
        self::assertSame(self::A, PluginGitpluginsRefResolver::tagCommitSha(['name' => 'v1', 'commit' => ['sha' => self::A]]));
        self::assertSame(self::B, PluginGitpluginsRefResolver::tagCommitSha(['name' => 'v1', 'commit' => ['id' => strtoupper(self::B)]]));
        self::assertSame('', PluginGitpluginsRefResolver::tagCommitSha(['name' => 'v1', 'commit' => ['sha' => 'main']]));
        self::assertSame('', PluginGitpluginsRefResolver::tagCommitSha(['name' => 'v1']));
    }

    public function testReviewedShaFallsBackToTheTagCommit(): void
    {
        self::assertSame(self::A, PluginGitpluginsInstaller::reviewedSha(['sha' => '', 'tag_sha' => self::A]));
        self::assertSame(self::B, PluginGitpluginsInstaller::reviewedSha(['sha' => self::B, 'tag_sha' => self::A]));
        // The confirmation now binds the tag's commit.
        self::assertFalse(PluginGitpluginsInstaller::confirmMatches('v1.4.0', self::A, ['ref' => 'v1.4.0', 'sha' => '', 'tag_sha' => self::B]));
        self::assertTrue(PluginGitpluginsInstaller::confirmMatches('v1.4.0', self::A, ['ref' => 'v1.4.0', 'sha' => '', 'tag_sha' => self::A]));
    }

    public function testTagJobFetchesTheCommitNotTheTagName(): void
    {
        $t = PluginGitpluginsUpdatecheck::pinnedTarget([], ['ref' => 'v1.4.0', 'sha' => '', 'tag_sha' => self::A], 'latest_tag');
        self::assertSame(['ref' => self::A, 'sha' => self::A], $t);
        self::assertSame('v1.4.0', PluginGitpluginsUpdatecheck::targetTag([], ['ref' => 'v1.4.0'], ''));
        // The bulk plan queues a ref without a commit: it is completed from the resolver.
        $row = PluginGitpluginsUpdatecheck::completePin(['pending_ref' => 'v1.4.0', 'pending_sha' => null], ['ref' => 'v1.4.0', 'tag_sha' => self::A], 'latest_tag');
        self::assertSame(self::A, $row['pending_sha']);
        // A tag that moved to another name since is not adopted.
        $row = PluginGitpluginsUpdatecheck::completePin(['pending_ref' => 'v1.3.0', 'pending_sha' => null], ['ref' => 'v1.4.0', 'tag_sha' => self::A], 'latest_tag');
        self::assertNull($row['pending_sha']);
    }

    /**
     * Run Installer::run() with the network and core stubbed out; report what
     * was fetched / extracted and how the run ended.
     *
     * @return array{ok:bool,fetches:int,extracts:int,error:string}
     */
    private static function run(string $policy, array $installRow, string $ref, string $sha, string $tag, string $assetBytes = ''): array
    {
        $setup = sprintf(
            "const POLICY = %s;\nconst ROW = %s;\nconst REF = %s;\nconst SHA = %s;\nconst TAG = %s;\nconst ASSET = %s;\n",
            var_export($policy, true),
            var_export($installRow, true),
            var_export($ref, true),
            var_export($sha, true),
            var_export($tag, true),
            var_export($assetBytes, true)
        );
        $code = <<<'PHP'
final class PluginGitpluginsFetcher
{
    public static int $fetches = 0;
    public static function resolveReleaseAsset(...$a): array { return ['https://github.com/o/foo/releases/download/v2.0.0/foo.tgz', 'v2.0.0']; }
    public static function fetch(...$a): string
    {
        self::$fetches++;
        $f = tempnam(sys_get_temp_dir(), 'gp_fp_');
        file_put_contents($f, ASSET);
        return $f;
    }
}
final class PluginGitpluginsExtractor
{
    public const MAX_ENTRIES = 20000;
    public static int $extracts = 0;
    public static function extractTo(...$a): string { self::$extracts++; throw new \RuntimeException('stop here'); }
    public static function rrmdir(string $d): void {}
}
final class PluginGitpluginsDiscovery { public static function isMarketplacePlugin(string $k): bool { return false; } }
final class Plugin { public array $fields = []; public function getFromDBbyDir(string $d): bool { return false; } }
$GLOBALS['DB']->tables['glpi_plugin_gitplugins_installs'] = [array_merge(['id' => 1, 'plugin_key' => 'foo', 'plugin_gitplugins_sources_id' => 5], ROW)];
$source = ['id' => 5, 'plugin_key' => 'foo', 'provider' => 'github', 'url' => 'https://github.com/o/foo', 'ref_policy' => POLICY, 'credential' => null];
$ok = PluginGitpluginsInstaller::run($source, REF, SHA, TAG);
$row = $GLOBALS['DB']->tables['glpi_plugin_gitplugins_installs'][0];
echo json_encode(['ok' => $ok, 'fetches' => PluginGitpluginsFetcher::$fetches, 'extracts' => PluginGitpluginsExtractor::$extracts, 'error' => (string) ($row['last_error'] ?? '')]), "\n";
PHP;

        return GitpluginsHarness::run($setup . $code);
    }

    public function testRunRefusesAMovedTagBeforeDownloading(): void
    {
        $r = self::run('latest_tag', ['installed_tag' => 'v1.4.0', 'installed_sha' => self::A], self::B, self::B, 'v1.4.0');
        self::assertFalse($r['ok']);
        self::assertSame(0, $r['fetches'], 'nothing is downloaded');
        self::assertStringContainsString('tag_moved', $r['error']);
        self::assertStringContainsString('v1.4.0', $r['error'], 'the admin is told which tag');
    }

    public function testRunRefusesAMovedPinnedTag(): void
    {
        $r = self::run('pin_tag', ['installed_tag' => 'v1.4.0', 'installed_sha' => self::A], self::B, self::B, 'v1.4.0');
        self::assertFalse($r['ok']);
        self::assertSame(0, $r['fetches']);
    }

    /** Control: the unchanged tag is downloaded (the run then stops at the extractor stub). */
    public function testRunLetsAnUnchangedTagThrough(): void
    {
        $r = self::run('latest_tag', ['installed_tag' => 'v1.4.0', 'installed_sha' => self::A], self::A, self::A, 'v1.4.0');
        self::assertSame(1, $r['fetches']);
        self::assertSame(1, $r['extracts'], $r['error']);
        self::assertStringNotContainsString('tag_moved', $r['error']);
    }

    public function testRunRefusesAReplacedReleaseAssetBeforeOpeningIt(): void
    {
        $r = self::run('release', ['installed_tag' => 'v2.0.0', 'installed_asset_sha256' => hash('sha256', 'old asset')], 'v2.0.0', '', '', 'swapped asset');
        self::assertFalse($r['ok']);
        self::assertSame(1, $r['fetches']);
        self::assertSame(0, $r['extracts'], 'the swapped archive is never opened');
        self::assertStringContainsString('tag_moved', $r['error']);
    }

    /** The resolver asks the forge for the commit of the tag it picks (and of a pinned tag). */
    public function testResolverReturnsTheCommitOfTheTag(): void
    {
        $code = <<<'PHP'
final class PluginGitpluginsHttpclient
{
    public static function get(string $url, array $opts): array
    {
        $a = str_repeat('a', 40); $b = str_repeat('b', 40);
        return ['status' => 200, 'body' => json_encode([['name' => 'v1.0.0', 'commit' => ['sha' => $a]], ['name' => 'v1.1.0', 'commit' => ['sha' => $b]]])];
    }
}
$cfg = PluginGitpluginsConfig::singleton();
$src = ['provider' => 'github', 'url' => 'https://github.com/o/foo', 'ref_policy' => 'latest_tag', 'ref' => '', 'credential' => null];
$latest = PluginGitpluginsUpdatecheck::resolveLatest($src, $cfg);
$pinned = PluginGitpluginsUpdatecheck::resolveLatest(['ref_policy' => 'pin_tag', 'ref' => 'v1.0.0'] + $src, $cfg);
echo json_encode(['latest' => $latest, 'pinned' => $pinned]), "\n";
PHP;
        $r = GitpluginsHarness::run($code);
        self::assertSame('v1.1.0', $r['latest']['ref']);
        self::assertSame(str_repeat('b', 40), $r['latest']['tag_sha']);
        self::assertSame('', $r['latest']['sha'], 'availability still compares versions for tags');
        self::assertSame(str_repeat('a', 40), $r['pinned']['tag_sha']);
    }

    public function testExtractCapIsClampedToAFloor(): void
    {
        $code = <<<'PHP'
$GLOBALS['DB']->tables['glpi_plugin_gitplugins_config'] = [['id' => 1, 'max_extract_mb' => 1]];
echo json_encode(['low' => PluginGitpluginsConfig::singleton()->getMaxExtractMb()]), "\n";
PHP;
        self::assertSame(16, GitpluginsHarness::run($code)['low'], 'never below 16 MB');
    }

    public function testRunLetsTheSameReleaseAssetThrough(): void
    {
        $r = self::run('release', ['installed_tag' => 'v2.0.0', 'installed_asset_sha256' => hash('sha256', 'old asset')], 'v2.0.0', '', '', 'old asset');
        self::assertSame(1, $r['extracts']);
    }
}
