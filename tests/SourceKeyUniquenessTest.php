<?php
/**
 * A plugin key names one directory of the server whatever the entity: a second
 * source for a key that another source (of any entity) already manages is
 * refused on every write path of the model.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/harness/Harness.php';

final class SourceKeyUniquenessTest extends TestCase
{
    /** Two stored sources: #5 key "foo" in entity 0, #6 key "bar" in entity 2. */
    private static function model(string $body): array
    {
        $code = <<<'PHP'
define('PLUGIN_GITPLUGINS_ROOTDOC', '/plugins/gitplugins');
Session::$rights = ['plugin_gitplugins' => ALLSTANDARDRIGHT];
$t = PluginGitpluginsSource::getTable();
$GLOBALS['DB']->tables[$t] = [
  ['id' => 5, 'entities_id' => 0, 'name' => 'foo', 'url' => 'https://github.com/o/foo', 'host' => 'github.com',
   'provider' => 'github', 'plugin_key' => 'foo', 'ref_policy' => 'latest_tag', 'ref' => null, 'is_active' => 1, 'credential' => null],
  ['id' => 6, 'entities_id' => 2, 'name' => 'bar', 'url' => 'https://github.com/o/bar', 'host' => 'github.com',
   'provider' => 'github', 'plugin_key' => 'bar', 'ref_policy' => 'latest_tag', 'ref' => null, 'is_active' => 1, 'credential' => null],
];
$src = new PluginGitpluginsSource();
PHP;

        return GitpluginsHarness::run($code . "\n" . $body . <<<'PHP'

echo json_encode(['ok' => $ok, 'rows' => count($GLOBALS['DB']->tables[$t]), 'keys' => array_column($GLOBALS['DB']->tables[$t], 'plugin_key')]), "\n";
PHP);
    }

    /** A source of entity 1 cannot register the key held by entity 0. */
    public function testAddingAKeyHeldByAnotherEntityIsRefused(): void
    {
        $r = self::model('$ok = $src->add(["url" => "https://github.com/attacker/x", "plugin_key" => "foo", "entities_id" => 1]);');
        self::assertFalse($r['ok']);
        self::assertSame(2, $r['rows']);
    }

    public function testRepointingASourceToAHeldKeyIsRefused(): void
    {
        $r = self::model('$ok = $src->update(["id" => 6, "plugin_key" => "foo"]);');
        self::assertFalse($r['ok']);
        self::assertSame(['foo', 'bar'], $r['keys']);
    }

    /** The holder may live in any entity, not only the root one. */
    public function testKeyHeldByASubEntityIsRefusedToo(): void
    {
        $r = self::model('$ok = $src->update(["id" => 5, "plugin_key" => "bar"]);');
        self::assertFalse($r['ok']);
        self::assertSame(['foo', 'bar'], $r['keys']);
    }

    /** The key is normalised before the check: case and punctuation cannot dodge it. */
    public function testNormalisedKeyCannotDodgeTheCheck(): void
    {
        $r = self::model('$ok = $src->add(["url" => "https://github.com/attacker/x", "plugin_key" => "F-o.o", "entities_id" => 1]);');
        self::assertFalse($r['ok']);
        self::assertSame(2, $r['rows']);
    }

    /** Controls: a free key is accepted, and a source keeps editing its own key. */
    public function testFreeKeyAndOwnKeyStillWork(): void
    {
        $r = self::model('$ok = $src->add(["url" => "https://github.com/o/baz", "plugin_key" => "baz", "entities_id" => 1]);');
        self::assertTrue((bool) $r['ok']);
        self::assertSame(3, $r['rows']);

        $r = self::model('$ok = $src->update(["id" => 5, "plugin_key" => "foo", "name" => "renamed"]);');
        self::assertTrue($r['ok']);
    }
}
