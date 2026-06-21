<?php
/**
 * Pure tests for the plugin.xml <gitupdate> manifest parser + discovery decision.
 * No GLPI bootstrap / DB / network — the parser is side-effect-free.
 *
 *   cd plugins/gitplugins && phpunit tests/ManifestTest.php
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/manifest.class.php';
require_once __DIR__ . '/../inc/discovery.class.php';

final class ManifestTest extends TestCase
{
    private function xml(string $inner): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<root>\n<key>comm</key>\n{$inner}\n</root>";
    }

    public function testParsesValidBlock(): void
    {
        $m = PluginGitpluginsManifest::parseXml($this->xml(
            '<gitupdate><repo>https://git.convergent.tn/fbennasr/comm.git</repo>'
            . '<ref>main</ref><ref_type>branch</ref_type><provider>forgejo</provider>'
            . '<private>false</private></gitupdate>'
        ));
        self::assertIsArray($m);
        self::assertSame('https://git.convergent.tn/fbennasr/comm.git', $m['repo']);
        self::assertSame('main', $m['ref']);
        self::assertSame('branch', $m['ref_type']);
        self::assertSame('forgejo', $m['provider']);
        self::assertFalse($m['private']);
    }

    public function testMissingBlockReturnsNull(): void
    {
        self::assertNull(PluginGitpluginsManifest::parseXml($this->xml('<description>x</description>')));
    }

    public function testMalformedXmlReturnsNull(): void
    {
        self::assertNull(PluginGitpluginsManifest::parseXml('<root><gitupdate><repo>https://x.tn/a/b'));
        self::assertNull(PluginGitpluginsManifest::parseXml(''));
        self::assertNull(PluginGitpluginsManifest::parseXml('not xml at all'));
    }

    public function testNonHttpRepoRejected(): void
    {
        foreach ([
            'ftp://git.convergent.tn/a/b.git',
            'file:///etc/passwd',
            'git@github.com:foo/bar.git',
            'javascript:alert(1)',
            'not-a-url',
        ] as $bad) {
            self::assertNull(
                PluginGitpluginsManifest::parseXml($this->xml("<gitupdate><repo>{$bad}</repo></gitupdate>")),
                "expected null for repo: {$bad}"
            );
        }
    }

    public function testHttpRepoAccepted(): void
    {
        $m = PluginGitpluginsManifest::parseXml($this->xml('<gitupdate><repo>http://example.com/a/b.git</repo></gitupdate>'));
        self::assertIsArray($m);
        self::assertSame('http://example.com/a/b.git', $m['repo']);
    }

    public function testStripsCrLfAndWhitespace(): void
    {
        $m = PluginGitpluginsManifest::parseXml($this->xml(
            "<gitupdate><repo>  https://git.convergent.tn/x/y.git  </repo>"
            . "<ref>\r\n release/1.x \n</ref><ref_type> BRANCH </ref_type></gitupdate>"
        ));
        self::assertIsArray($m);
        self::assertSame('https://git.convergent.tn/x/y.git', $m['repo']);
        self::assertSame('release/1.x', $m['ref']);
        self::assertSame('branch', $m['ref_type']);
    }

    public function testPrivateTrueVariants(): void
    {
        foreach (['true', 'TRUE', '1', 'yes', 'on'] as $t) {
            $m = PluginGitpluginsManifest::parseXml($this->xml("<gitupdate><repo>https://x.tn/a/b.git</repo><private>{$t}</private></gitupdate>"));
            self::assertTrue($m['private'], "expected private=true for: {$t}");
        }
        foreach (['false', '0', 'no', '', 'off'] as $f) {
            $m = PluginGitpluginsManifest::parseXml($this->xml("<gitupdate><repo>https://x.tn/a/b.git</repo><private>{$f}</private></gitupdate>"));
            self::assertFalse($m['private'], "expected private=false for: '{$f}'");
        }
    }

    public function testDefaultRefTypeWhenMissingOrUnknown(): void
    {
        $m = PluginGitpluginsManifest::parseXml($this->xml('<gitupdate><repo>https://x.tn/a/b.git</repo></gitupdate>'));
        self::assertSame('branch', $m['ref_type']);
        $m2 = PluginGitpluginsManifest::parseXml($this->xml('<gitupdate><repo>https://x.tn/a/b.git</repo><ref_type>nonsense</ref_type></gitupdate>'));
        self::assertSame('branch', $m2['ref_type']);
    }

    public function testDiscoveryDecideState(): void
    {
        $valid = ['repo' => 'https://x.tn/a/b.git', 'ref' => 'main'];
        // A declared-but-unmanaged plugin → 'declared'.
        self::assertSame('declared', PluginGitpluginsDiscovery::decideState($valid, false));
        // A managed source wins regardless of declaration.
        self::assertSame('managed', PluginGitpluginsDiscovery::decideState($valid, true));
        self::assertSame('managed', PluginGitpluginsDiscovery::decideState(null, true));
        self::assertSame('managed', PluginGitpluginsDiscovery::decideState(['repo' => ''], true));
        // No declaration, not managed → 'none' (offers a bare Add source).
        self::assertSame('none', PluginGitpluginsDiscovery::decideState(null, false));
        self::assertSame('none', PluginGitpluginsDiscovery::decideState(['repo' => ''], false));
    }

    // ----- parseInfo(): key / name / version extraction -----

    private function full(string $inner): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<root>\n{$inner}\n</root>";
    }

    public function testParseInfoExtractsKeyNameVersionAndGitupdate(): void
    {
        $info = PluginGitpluginsManifest::parseInfo($this->full(
            '<key>comm</key><name>Internal Comms</name>'
            . '<versions><version><num>1.0.0</num></version><version><num>1.2.0</num></version>'
            . '<version><num>1.10.0</num></version></versions>'
            . '<gitupdate><repo>https://git.convergent.tn/a/comm.git</repo><provider>forgejo</provider>'
            . '<ref>main</ref><ref_type>branch</ref_type></gitupdate>'
        ));
        self::assertIsArray($info);
        self::assertSame('comm', $info['key']);
        self::assertSame('Internal Comms', $info['name']);
        // Highest <num> wins (1.10.0 > 1.2.0, not a string compare).
        self::assertSame('1.10.0', $info['version']);
        self::assertIsArray($info['gitupdate']);
        self::assertSame('forgejo', $info['gitupdate']['provider']);
    }

    public function testParseInfoFallsBackToTopLevelVersion(): void
    {
        $info = PluginGitpluginsManifest::parseInfo($this->full('<key>x</key><name>X</name><version>2.3.4</version>'));
        self::assertSame('2.3.4', $info['version']);
        self::assertNull($info['gitupdate']);
    }

    public function testParseInfoMissingFieldsAreEmptyNotNull(): void
    {
        // No key/name/version/gitupdate at all — parses, fields are ''.
        $info = PluginGitpluginsManifest::parseInfo($this->full('<description>nothing useful</description>'));
        self::assertIsArray($info);
        self::assertSame('', $info['key']);
        self::assertSame('', $info['name']);
        self::assertSame('', $info['version']);
        self::assertNull($info['gitupdate']);
    }

    public function testParseInfoMalformedReturnsNull(): void
    {
        self::assertNull(PluginGitpluginsManifest::parseInfo(''));
        self::assertNull(PluginGitpluginsManifest::parseInfo('not xml at all <'));
        self::assertNull(PluginGitpluginsManifest::parseInfo('<root><key>x'));
    }

    public function testParseInfoTrimsCrLf(): void
    {
        $info = PluginGitpluginsManifest::parseInfo($this->full("<key> \r\n comm \n</key><name>\r\nC\n</name>"));
        self::assertSame('comm', $info['key']);
        self::assertSame('C', $info['name']);
    }
}
