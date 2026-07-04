<?php
/**
 * Pure tests for the convergent catalog (Phase 10): manifest parse/validate and
 * entry normalisation. refresh()/cached() are integration (network/DB).
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/catalog.class.php';

final class CatalogTest extends TestCase
{
    public function testParsesPluginsWrapperAndBareList(): void
    {
        $wrapped = '{"plugins":[{"plugin_key":"comm","url":"https://git.convergent.tn/x/comm"}]}';
        $bare    = '[{"plugin_key":"comm","url":"https://git.convergent.tn/x/comm"}]';
        self::assertSame(1, count(PluginGitpluginsCatalog::parseManifest($wrapped)));
        self::assertSame(1, count(PluginGitpluginsCatalog::parseManifest($bare)));
    }

    public function testInvalidJsonYieldsEmpty(): void
    {
        self::assertSame([], PluginGitpluginsCatalog::parseManifest('not json'));
        self::assertSame([], PluginGitpluginsCatalog::parseManifest('123'));
    }

    public function testEntryNeedsValidKeyAndHttpsUrl(): void
    {
        self::assertNull(PluginGitpluginsCatalog::normaliseEntry(['plugin_key' => 'Bad Key', 'url' => 'https://h/x']));
        self::assertNull(PluginGitpluginsCatalog::normaliseEntry(['plugin_key' => 'ok', 'url' => 'http://h/x']));
        self::assertNull(PluginGitpluginsCatalog::normaliseEntry(['plugin_key' => 'ok', 'url' => 'ftp://h/x']));
        self::assertNull(PluginGitpluginsCatalog::normaliseEntry(['plugin_key' => '', 'url' => 'https://h/x']));
        $ok = PluginGitpluginsCatalog::normaliseEntry(['plugin_key' => 'ok', 'url' => 'https://h/x']);
        self::assertIsArray($ok);
        self::assertSame('ok', $ok['plugin_key']);
    }

    public function testKeyAndRepoAliasesAccepted(): void
    {
        // Accept `key`/`repo` as aliases for `plugin_key`/`url`.
        $e = PluginGitpluginsCatalog::normaliseEntry(['key' => 'comm', 'repo' => 'https://git.convergent.tn/x/comm']);
        self::assertIsArray($e);
        self::assertSame('comm', $e['plugin_key']);
        self::assertSame('https://git.convergent.tn/x/comm', $e['url']);
    }

    public function testRefPolicyConstrainedToEnum(): void
    {
        $good = PluginGitpluginsCatalog::normaliseEntry(['plugin_key' => 'a', 'url' => 'https://h/a', 'ref_policy' => 'release']);
        self::assertSame('release', $good['ref_policy']);
        $bad = PluginGitpluginsCatalog::normaliseEntry(['plugin_key' => 'a', 'url' => 'https://h/a', 'ref_policy' => 'nonsense']);
        self::assertSame('latest_tag', $bad['ref_policy']);
    }

    public function testDedupByKeyFirstWins(): void
    {
        $json = '[
            {"plugin_key":"a","url":"https://h/a1","name":"first"},
            {"plugin_key":"a","url":"https://h/a2","name":"second"}
        ]';
        $out = PluginGitpluginsCatalog::parseManifest($json);
        self::assertSame(1, count($out));
        self::assertSame('first', $out[0]['name']);
    }

    public function testCleanFieldsAndDefaults(): void
    {
        $e = PluginGitpluginsCatalog::normaliseEntry([
            'plugin_key'  => 'a',
            'url'         => 'https://h/a',
            'name'        => "Line\nBreak",
            'category'    => 'inventory',
            'description' => 'desc',
        ]);
        self::assertStringNotContainsString("\n", $e['name']);
        self::assertSame('inventory', $e['category']);
        self::assertSame('desc', $e['description']);
        // Missing name → falls back to the key.
        $e2 = PluginGitpluginsCatalog::normaliseEntry(['plugin_key' => 'a', 'url' => 'https://h/a']);
        self::assertSame('a', $e2['name']);
    }

    public function testKnownIssuesCarriedThrough(): void
    {
        $e = PluginGitpluginsCatalog::normaliseEntry([
            'plugin_key'   => 'a',
            'url'          => 'https://h/a',
            'known_issues' => [['kind' => 'advisory', 'message' => 'x']],
        ]);
        self::assertSame(1, count($e['known_issues']));
    }

    public function testParseUrlListValidatesDedupesAndCaps(): void
    {
        $raw = "https://a.example/c.json\n  https://b.example/c.json \nhttp://insecure.example/x\nnot-a-url\nhttps://a.example/c.json";
        $urls = PluginGitpluginsCatalog::parseUrlList($raw);
        // https only, host required, de-duplicated (a.example once).
        self::assertSame(['https://a.example/c.json', 'https://b.example/c.json'], $urls);
    }

    public function testParseUrlListEmptyAndWhitespace(): void
    {
        self::assertSame([], PluginGitpluginsCatalog::parseUrlList(''));
        self::assertSame([], PluginGitpluginsCatalog::parseUrlList("\n  \n\t"));
    }

    public function testParseUrlListIsVendorNeutral(): void
    {
        // No host is privileged — any company's own catalog host is accepted here
        // (the SSRF allow-list still gates the actual fetch).
        $urls = PluginGitpluginsCatalog::parseUrlList("https://plugins.acme-corp.io/catalog.json");
        self::assertSame(['https://plugins.acme-corp.io/catalog.json'], $urls);
    }

    public function testMalformedEntriesSkippedNotFatal(): void
    {
        $json = '[
            {"plugin_key":"good","url":"https://h/good"},
            {"nope":"missing"},
            "a string",
            {"plugin_key":"BAD KEY","url":"https://h/x"}
        ]';
        $out = PluginGitpluginsCatalog::parseManifest($json);
        self::assertSame(1, count($out));
        self::assertSame('good', $out[0]['plugin_key']);
    }
}
