<?php
/**
 * L-12 — inline confirm() handlers survive any translation. The French
 * "n'est PAS" used to close the JS string: the handler threw and the form was
 * submitted WITHOUT confirmation.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../inc/ui.class.php';

final class ConfirmEncodingTest extends TestCase
{
    /** What the browser runs: the attribute value after HTML decoding. */
    private static function handlerFor(string $message): string
    {
        return html_entity_decode(PluginGitpluginsUi::confirmAttr($message), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function testConfirmMessageSurvivesApostrophe(): void
    {
        foreach ([
            "Retirer cette source de la gestion ? Le plugin installé n'est PAS désinstallé.",
            'Say "yes" </script><script>alert(1)</script> & go',
            "Line\nbreak \\ back'slash",
        ] as $msg) {
            $attr = PluginGitpluginsUi::confirmAttr($msg);
            // Nothing in the attribute can end it early or open markup.
            foreach (['"', "'", '<', '>'] as $c) {
                self::assertStringNotContainsString($c, $attr, 'raw ' . $c . ' in attribute');
            }
            $js = self::handlerFor($msg);
            self::assertSame(1, preg_match('/^return confirm\((.*)\);$/s', $js, $m), $js);
            // The argument is a single JS string literal that evaluates to the message.
            self::assertSame($msg, json_decode($m[1], true, 2, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString("'", $m[1], 'no quote can close the literal');
        }
    }

    /** Every shipped French confirmation goes through the encoder. */
    public function testNoHtmlEscapedStringInsideJsHandler(): void
    {
        foreach (glob(__DIR__ . '/../front/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            self::assertSame(0, preg_match("/confirm\\('<\\?=/", $src), basename($file) . ': confirm() must use PluginGitpluginsUi::confirmAttr');
        }
    }

    public function testShippedFrenchConfirmationsRoundTrip(): void
    {
        $po = (string) file_get_contents(__DIR__ . '/../locales/fr_FR.po');
        $checked = 0;
        foreach (['Remove this source from management?', 'Roll back this plugin', 'Queue this install', 'Queue the selected plugins', 'Remove this target?'] as $needle) {
            if (preg_match('/msgid "' . preg_quote($needle, '/') . '[^"]*"\s*\nmsgstr "((?:[^"\\\\]|\\\\.)*)"/', $po, $m) === 1) {
                $fr = stripcslashes($m[1]);
                $js = self::handlerFor($fr);
                self::assertSame(1, preg_match('/^return confirm\((.*)\);$/s', $js, $mm));
                self::assertSame($fr, json_decode($mm[1], true));
                $checked++;
            }
        }
        self::assertTrue($checked >= 1, 'at least the removal confirmation is translated');
    }
}
