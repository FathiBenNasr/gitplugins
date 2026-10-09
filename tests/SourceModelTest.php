<?php
/**
 * L-27 / L-15 — the business rules of a source live in the model, so a write
 * through the REST API or a massive action (which call add()/update() directly,
 * never front/source.form.php) gets the same checks. Runs the real
 * PluginGitpluginsSource in the harness.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/harness/Harness.php';

final class SourceModelTest extends TestCase
{
    /** Run $body with a stored source #5 (github, entity 0) and report the outcome. */
    private static function model(string $body): array
    {
        $code = <<<'PHP'
define('PLUGIN_GITPLUGINS_ROOTDOC', '/plugins/gitplugins');
Session::$rights = ['plugin_gitplugins' => ALLSTANDARDRIGHT];
$t = PluginGitpluginsSource::getTable();
$GLOBALS['DB']->tables[$t] = [[
    'id' => 5, 'entities_id' => 0, 'name' => 'foo', 'url' => 'https://github.com/o/foo',
    'host' => 'github.com', 'provider' => 'github', 'plugin_key' => 'foo', 'ref_policy' => 'latest_tag',
    'ref' => null, 'is_active' => 1, 'credential' => null, 'build_on_install' => 0,
]];
$src = new PluginGitpluginsSource();
PHP;

        return GitpluginsHarness::run($code . "\n" . $body . <<<'PHP'

echo json_encode([
    'ok'       => $ok,
    'row'      => $GLOBALS['DB']->tables[$t][0],
    'rows'     => count($GLOBALS['DB']->tables[$t]),
    'messages' => array_column(Session::$messages, 0),
]), "\n";
PHP);
    }

    public function testSourceUpdateIgnoresBuildOnInstall(): void
    {
        $r = self::model('$ok = $src->update(["id" => 5, "build_on_install" => 1, "is_active" => 0]);');
        self::assertTrue($r['ok']);
        self::assertSame(0, $r['row']['build_on_install'], 'third-party build code stays off');
        self::assertSame(0, $r['row']['is_active'], 'the legitimate part of the write applies');
    }

    public function testApiWriteCannotBypassHostAllowlistOrHttps(): void
    {
        foreach (['https://evil.example/o/foo', 'http://github.com/o/foo'] as $url) {
            $r = self::model('$ok = $src->update(["id" => 5, "url" => ' . var_export($url, true) . ']);');
            self::assertFalse($r['ok'], $url);
            self::assertSame('https://github.com/o/foo', $r['row']['url'], $url . ' must not be stored');
            self::assertTrue($r['messages'] !== []);
        }
        // Creation through the API gets the same gate.
        $r = self::model('$ok = $src->add(["url" => "https://evil.example/o/bar", "plugin_key" => "bar", "entities_id" => 0]);');
        self::assertFalse($r['ok']);
        self::assertSame(1, $r['rows']);
    }

    public function testProviderAndHostAreDerivedNotTrusted(): void
    {
        $r = self::model('$ok = $src->update(["id" => 5, "url" => "https://gitlab.com/o/foo", "provider" => "local", "host" => "127.0.0.1"]);');
        self::assertTrue($r['ok']);
        self::assertSame('gitlab', $r['row']['provider']);
        self::assertSame('gitlab.com', $r['row']['host']);
    }

    public function testCredentialIsEncryptedOnEveryPathAndUndisclosed(): void
    {
        $r = self::model('$ok = $src->update(["id" => 5, "credential" => "example-repo-token"]);');
        self::assertTrue($r['ok']);
        self::assertTrue(str_starts_with((string) $r['row']['credential'], 'enc:'), 'stored encrypted');
        self::assertStringNotContainsString('example-repo-token', (string) $r['row']['credential']);

        $r = self::model('$ok = $src->update(["id" => 5, "_clear_credential" => 1]);');
        self::assertNull($r['row']['credential']);

        $r = self::model('$ok = PluginGitpluginsSource::$undisclosedFields;');
        self::assertContains('credential', $r['ok']);
    }

    public function testMarketplaceKeyRejected(): void
    {
        $r = self::model(<<<'PHP'
$res = PluginGitpluginsSource::validateInput(
    ['url' => 'https://github.com/o/datainjection', 'plugin_key' => 'datainjection', 'ref_policy' => 'latest_tag'],
    ['github.com'],
    false,
    [],
    static fn (string $k): bool => $k === 'datainjection'
);
$ok = $res['errors'];
PHP);
        self::assertSame(1, count($r['ok']));
        self::assertStringContainsString('marketplace', $r['ok'][0]);

        // Same input, key not marketplace-managed → valid.
        $r = self::model(<<<'PHP'
$res = PluginGitpluginsSource::validateInput(
    ['url' => 'https://github.com/o/foo', 'plugin_key' => 'foo'],
    ['github.com'],
    false,
    [],
    static fn (string $k): bool => false
);
$ok = $res['errors'];
PHP);
        self::assertSame([], $r['ok']);
    }
}
