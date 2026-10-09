<?php
/**
 * Git Plugin Installer — small HTML/JS output helpers shared by front/ pages.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsUi
{
    /**
     * PURE: the value of an inline `onclick`/`onsubmit` attribute asking the
     * browser to confirm $message, safe for any translation.
     *
     * WHY: `confirm('<?= htmlspecialchars(...) ?>')` HTML-encodes the apostrophe,
     * the browser decodes it back before running the handler, and the French
     * "n'est PAS" closed the JS string: the handler threw, and the form was
     * submitted WITHOUT confirmation (source removal, rollback…). A JS string in
     * an HTML attribute needs JS encoding first (json_encode, every quote and
     * markup character hex-escaped), then HTML encoding.
     */
    public static function confirmAttr(string $message): string
    {
        $js = json_encode(
            $message,
            JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        return htmlspecialchars('return confirm(' . $js . ');', ENT_QUOTES);
    }
}
