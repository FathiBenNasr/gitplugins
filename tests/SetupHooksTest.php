<?php
/**
 * setup.php / hook.php behaviours, run for real in the harness:
 *  - L-14: an unmet prerequisite is reported, not turned into a TypeError;
 *  - L-11: audit events reach GLPI's event log, and uninstall keeps the audit;
 *  - L-18: a reinstall never re-grants a right an administrator took away;
 *  - L-24: audit rows past the retention period are purged.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/harness/Harness.php';

final class SetupHooksTest extends TestCase
{
    public function testPrereqFailureEchoesTextWithoutTypeError(): void
    {
        $code = <<<'PHP'
require GP_PLUGIN_DIR . '/setup.php';
ob_start();
try {
    $ok = plugin_gitplugins_check_prerequisites();
    $err = '';
} catch (\Throwable $e) {
    $ok = null;
    $err = get_class($e);
}
$out = (string) ob_get_clean();
echo json_encode(['ok' => $ok, 'err' => $err, 'out' => $out]), "\n";
PHP;
        $r = GitpluginsHarness::run($code, ['GP_GLPI_VERSION' => '10.0.18']);
        self::assertSame('', $r['err'], 'no exception may escape check_prerequisites');
        self::assertFalse($r['ok']);
        self::assertStringContainsString('requires GLPI', $r['out']);

        // And the supported version passes silently (the line's own minimum).
        preg_match("/PLUGIN_GITPLUGINS_MIN_GLPI', '([0-9.]+)'/", (string) file_get_contents(__DIR__ . '/../setup.php'), $m);
        $r = GitpluginsHarness::run($code, ['GP_GLPI_VERSION' => $m[1] ?? '']);
        self::assertTrue($r['ok']);
        self::assertSame('', $r['out']);
    }

    public function testEventLogClassResolved(): void
    {
        $code = <<<'PHP'
$GLOBALS['DB']->tables[PluginGitpluginsLog::TABLE] = [];
PluginGitpluginsLog::record(4, 'install', 'ok', 'installed foo');
echo json_encode(['events' => \Glpi\Event::$logged, 'rows' => count($GLOBALS['DB']->tables[PluginGitpluginsLog::TABLE])]), "\n";
PHP;
        $r = GitpluginsHarness::run($code);
        self::assertSame(1, $r['rows']);
        self::assertSame(1, count($r['events']), 'the audit line must reach Glpi\\Event::log()');
        self::assertSame('gitplugins', $r['events'][0][1]);
    }

    public function testExpiredAuditRowsArePurged(): void
    {
        $code = <<<'PHP'
$now = strtotime('2026-10-09 12:00:00');
$GLOBALS['DB']->tables[PluginGitpluginsLog::TABLE] = [
    ['id' => 1, 'date_creation' => '2025-09-01 00:00:00'],
    ['id' => 2, 'date_creation' => '2025-11-01 00:00:00'],
    ['id' => 3, 'date_creation' => '2026-10-08 00:00:00'],
];
PluginGitpluginsLog::purgeExpired($now);
echo json_encode(array_column($GLOBALS['DB']->tables[PluginGitpluginsLog::TABLE], 'id')), "\n";
PHP;
        self::assertSame([2, 3], GitpluginsHarness::run($code));
        self::assertSame('2025-10-09 12:00:00', PluginGitpluginsLogCutoffProbe::cutoff());
    }

    public function testReinstallKeepsARevokedRight(): void
    {
        $code = <<<'PHP'
require GP_PLUGIN_DIR . '/hook.php';
$t = 'glpi_profilerights';
$GLOBALS['DB']->tables[$t] = [
    ['id' => 1, 'profiles_id' => 4, 'name' => 'config', 'rights' => 31],
    ['id' => 2, 'profiles_id' => 4, 'name' => 'plugin_gitplugins', 'rights' => 0],  // taken away by an admin
    ['id' => 3, 'profiles_id' => 5, 'name' => 'config', 'rights' => 31],           // never had it
];
// The install hook selects the profiles holding config write access: 4 and 5.
plugin_gitplugins_grant_rights($GLOBALS['DB'], [['profiles_id' => 4], ['profiles_id' => 5]]);
$out = [];
foreach ($GLOBALS['DB']->tables[$t] as $row) {
    if ($row['name'] === 'plugin_gitplugins') {
        $out[(string) $row['profiles_id']] = $row['rights'];
    }
}
ksort($out);
echo json_encode($out), "\n";
PHP;
        self::assertSame(['4' => 0, '5' => 31], GitpluginsHarness::run($code));
    }

    public function testUninstallKeepsAudit(): void
    {
        $code = <<<'PHP'
require GP_PLUGIN_DIR . '/hook.php';
$db = $GLOBALS['DB'];
$db->tables[PluginGitpluginsLog::TABLE] = [['id' => 1]];
$db->tables[PluginGitpluginsDeploy::NONCE_TABLE] = [];
plugin_gitplugins_uninstall();
$touchingLogs = array_values(array_filter(
    array_column(array_filter($db->log, static fn (array $e): bool => $e[0] === 'query'), 1),
    static fn (string $q): bool => str_contains($q, PluginGitpluginsLog::TABLE)
));
echo json_encode(['logs' => $touchingLogs, 'rights' => ProfileRight::$removed]), "\n";
PHP;
        $r = GitpluginsHarness::run($code);
        self::assertSame(1, count($r['logs']), 'only one statement may touch the audit table');
        self::assertTrue(str_starts_with($r['logs'][0], 'RENAME TABLE '), $r['logs'][0]);
        self::assertStringContainsString('_archived_', $r['logs'][0]);
        self::assertSame(['plugin_gitplugins'], $r['rights']);
    }
}

/** Pure check of the retention cutoff without loading the class in-process twice. */
final class PluginGitpluginsLogCutoffProbe
{
    public static function cutoff(): string
    {
        require_once __DIR__ . '/../inc/log.class.php';

        return PluginGitpluginsLog::purgeCutoff((int) strtotime('2026-10-09 12:00:00'));
    }
}
