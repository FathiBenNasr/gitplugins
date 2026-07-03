<?php
/**
 * Git Plugin Installer — hook-collision detector (Phase 6).
 *
 * Predicts the class of failure geninventorynumber caused: its pre_item_update
 * hook silently reverted `otherserial` on Computer, 500-ing native inventory.
 * After an install, we read the freshly-active plugin's registered $PLUGIN_HOOKS
 * item-hook entries and compare them against every OTHER active plugin's
 * registrations for the SAME hook + itemtype, reporting overlaps as warnings —
 * "both X and Y hook pre_item_update on Computer; order/precedence may conflict".
 *
 * Read-only introspection of the in-memory $PLUGIN_HOOKS map — never modifies
 * another plugin. The overlap detection (detect/normaliseItemtypes/format) is
 * PURE and unit-tested; evaluate() just feeds it the live global map.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

final class PluginGitpluginsHookcheck
{
    /**
     * The GLPI CommonDBTM "business" hooks keyed by itemtype — the ones where two
     * plugins mutating the same item can fight (the geninventorynumber class of
     * bug). Deliberately NOT the wiring hooks (menu_toadd, config_page, cron, …),
     * which legitimately coexist. Kept as literals so the pure core needs no GLPI.
     *
     * @return string[]
     */
    public static function itemHookNames(): array
    {
        return [
            'pre_item_add',
            'item_add',
            'pre_item_update',
            'item_update',
            'pre_item_purge',
            'item_purge',
            'pre_item_delete',
            'item_delete',
            'item_restore',
            'pre_item_restore',
        ];
    }

    /**
     * PURE: given the subject plugin key, the whole $PLUGIN_HOOKS map, and the set
     * of item-hook names to inspect, return every overlap where ANOTHER plugin
     * hooks the same hook + itemtype as the subject.
     *
     * $pluginHooks is the native shape: $pluginHooks[hookName][pluginKey] = entry,
     * where entry is either a bare callable (applies to every itemtype → '*') or
     * an itemtype-keyed array ['Computer' => callable, …]. A '*' on either side
     * collides with any specific itemtype on the other.
     *
     * @param array<string,array<string,mixed>> $pluginHooks
     * @param string[]                          $hookNames
     * @return array<int,array{hook:string,itemtype:string,peer:string}> deduped, sorted
     */
    public static function detect(string $subjectKey, array $pluginHooks, array $hookNames): array
    {
        $subjectKey = trim($subjectKey);
        if ($subjectKey === '') {
            return [];
        }

        $out = [];
        foreach ($hookNames as $hook) {
            $reg = $pluginHooks[$hook] ?? null;
            if (!is_array($reg) || !array_key_exists($subjectKey, $reg)) {
                continue;
            }
            $subjectTypes = self::normaliseItemtypes($reg[$subjectKey]);
            if ($subjectTypes === []) {
                continue;
            }
            foreach ($reg as $peerKey => $entry) {
                if ((string) $peerKey === $subjectKey) {
                    continue;
                }
                $peerTypes = self::normaliseItemtypes($entry);
                foreach (self::overlappingTypes($subjectTypes, $peerTypes) as $itemtype) {
                    $out[$hook . "\0" . $itemtype . "\0" . $peerKey] = [
                        'hook'     => $hook,
                        'itemtype' => $itemtype,
                        'peer'     => (string) $peerKey,
                    ];
                }
            }
        }

        $out = array_values($out);
        // Stable order so the cached JSON + tests are deterministic.
        usort($out, static fn (array $a, array $b): int =>
            [$a['hook'], $a['itemtype'], $a['peer']] <=> [$b['hook'], $b['itemtype'], $b['peer']]);

        return $out;
    }

    /**
     * PURE: the itemtypes a single $PLUGIN_HOOKS[hook][plugin] entry applies to.
     * An itemtype-keyed array → its string keys; a bare callable/closure/string →
     * ['*'] (applies to every item). An empty array → [] (nothing registered).
     *
     * @param mixed $entry
     * @return string[]
     */
    public static function normaliseItemtypes($entry): array
    {
        if (is_array($entry)) {
            if ($entry === []) {
                return [];
            }
            $types = [];
            foreach ($entry as $k => $_v) {
                // Itemtype-keyed maps use non-empty STRING keys (class names).
                // A list (int keys) is a plain callable array → wildcard.
                if (is_string($k) && $k !== '') {
                    $types[$k] = $k;
                } else {
                    return ['*'];
                }
            }

            return array_values($types);
        }

        // Bare callable string / Closure / object → applies to all itemtypes.
        return ['*'];
    }

    /**
     * PURE: the itemtypes on which two entries collide. A '*' (all-itemtypes) on
     * either side collides with every specific type on the other; two wildcards
     * collide on '*'. Otherwise it is the set intersection.
     *
     * @param string[] $a
     * @param string[] $b
     * @return string[]
     */
    public static function overlappingTypes(array $a, array $b): array
    {
        $aStar = in_array('*', $a, true);
        $bStar = in_array('*', $b, true);

        if ($aStar && $bStar) {
            return ['*'];
        }
        if ($aStar) {
            return array_values(array_filter($b, static fn ($t) => $t !== '*'));
        }
        if ($bStar) {
            return array_values(array_filter($a, static fn ($t) => $t !== '*'));
        }

        return array_values(array_intersect($a, $b));
    }

    /**
     * PURE: render collisions as short human warning strings (for logs / UI).
     *
     * @param array<int,array{hook:string,itemtype:string,peer:string}> $collisions
     * @return string[]
     */
    public static function format(string $subjectKey, array $collisions): array
    {
        $out = [];
        foreach ($collisions as $c) {
            $target = $c['itemtype'] === '*' ? 'all itemtypes' : $c['itemtype'];
            $out[] = sprintf(
                'both %s and %s hook %s on %s — order/precedence may conflict',
                $subjectKey,
                $c['peer'],
                $c['hook'],
                $target
            );
        }

        return $out;
    }

    /**
     * Live: introspect the in-memory $PLUGIN_HOOKS map for overlaps between the
     * just-installed plugin and every other active plugin. Read-only. Returns the
     * collision list (empty when none / on any error) — never throws into run().
     *
     * @return array<int,array{hook:string,itemtype:string,peer:string}>
     */
    public static function evaluate(string $key): array
    {
        try {
            /** @var array $PLUGIN_HOOKS */
            global $PLUGIN_HOOKS;
            if (!is_array($PLUGIN_HOOKS)) {
                return [];
            }

            return self::detect($key, $PLUGIN_HOOKS, self::itemHookNames());
        } catch (\Throwable $e) {
            return [];
        }
    }
}
