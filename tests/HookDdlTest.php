<?php
/**
 * Regression guard: the install DDL in hook.php must not interpolate PHP
 * variables by accident.
 *
 * The `hook_warnings` column comment mentions $PLUGIN_HOOKS inside a
 * double-quoted SQL string. Unescaped, PHP interpolated the (undefined)
 * variable: a warning on every fresh install and a column comment cut down to
 * "post-install  collisions" (1.0.2).
 *
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HookDdlTest extends TestCase
{
    /** @return list<string> variables interpolated inside double-quoted strings / heredocs */
    private static function interpolated(string $file): array
    {
        $inside = false;
        $vars = [];
        foreach (token_get_all((string) file_get_contents($file)) as $t) {
            if ($t === '"') {
                $inside = !$inside;
                continue;
            }
            if (is_array($t)) {
                if ($t[0] === T_START_HEREDOC) {
                    $inside = !str_contains($t[1], "'");   // nowdoc does not interpolate
                } elseif ($t[0] === T_END_HEREDOC) {
                    $inside = false;
                } elseif ($inside && $t[0] === T_VARIABLE) {
                    $vars[] = $t[1];
                }
            }
        }

        return $vars;
    }

    public function testPluginHooksIsNeverInterpolated(): void
    {
        $this->assertFalse(in_array('$PLUGIN_HOOKS', self::interpolated(__DIR__ . '/../hook.php'), true));
    }
}
