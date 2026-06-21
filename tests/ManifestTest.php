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
        self::assertSame('unmanaged', PluginGitpluginsDiscovery::decideState($valid, false));
        self::assertSame('managed', PluginGitpluginsDiscovery::decideState($valid, true));
        self::assertSame('none', PluginGitpluginsDiscovery::decideState(null, false));
        self::assertSame('none', PluginGitpluginsDiscovery::decideState(['repo' => ''], false));
    }
}
