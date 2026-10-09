<?php
/**
 * Minimal GLPI core stand-ins for the out-of-process test harness (run.php).
 *
 * The plugin's front/ajax scripts and its DB-backed "live" methods are loaded
 * UNCHANGED against these stubs, so the abuse-case tests exercise the shipped
 * code rather than a copy of its logic. Only what the plugin touches is stubbed;
 * every stub records what it was asked to do so a test can assert that nothing
 * was written on a refused path.
 *
 * Loaded in a child PHP process only (see GitpluginsHarness::run), never in the
 * in-process suite, so these stubs cannot leak into the pure tests.
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

namespace Glpi\Exception\Http {
    class AccessDeniedHttpException extends \RuntimeException {}
    class NotFoundHttpException extends \RuntimeException {}
}

namespace Glpi\Http {
    final class Firewall
    {
        public const STRATEGY_NO_CHECK      = 'no_check';
        public const STRATEGY_AUTHENTICATED = 'authenticated';

        /** @var array<string,array<string,string>> */
        public static array $strategies = [];

        public static function addPluginStrategyForLegacyScripts(string $key, string $pattern, string $strategy): void
        {
            self::$strategies[$key][$pattern] = $strategy;
        }
    }

    final class SessionManager
    {
        /** @var array<string,string[]> */
        public static array $stateless = [];

        public static function registerPluginStatelessPath(string $key, string $pattern): void
        {
            self::$stateless[$key][] = $pattern;
        }
    }
}

namespace Glpi\Plugin {
    final class Hooks
    {
        public const SECURED_FIELDS = 'secured_fields';
    }
}

namespace Glpi {
    final class Event
    {
        /** @var array<int,array<int,mixed>> */
        public static array $logged = [];

        public static function log($items_id, $type, $level, $service, $event): void
        {
            self::$logged[] = [$items_id, $type, $level, $service, $event];
        }
    }
}

namespace {
    const READ             = 1;
    const UPDATE           = 2;
    const CREATE           = 4;
    const DELETE           = 8;
    const PURGE            = 16;
    const ALLSTANDARDRIGHT = 31;
    const INFO             = 0;
    const ERROR            = 1;
    const WARNING          = 2;
    const MINUTE_TIMESTAMP = 60;
    const HOUR_TIMESTAMP   = 3600;
    const DAY_TIMESTAMP    = 86400;

    if (!defined('GLPI_VERSION')) {
        define('GLPI_VERSION', getenv('GP_GLPI_VERSION') ?: '11.0.7');
    }

    /** Thrown by the Html::redirect stub so a script stops where GLPI would. */
    final class HarnessRedirect extends \RuntimeException {}

    function __($s, $d = '')
    {
        return $s;
    }

    function _n($s, $p, $n, $d = '')
    {
        return $n > 1 ? $p : $s;
    }

    function getEntitiesRestrictCriteria($table = '', $field = '', $value = '', $recursive = false): array
    {
        return [];
    }

    function isIndex(string $table, string $name): bool
    {
        return true;
    }

    final class Session
    {
        /** @var array<string,int> right name => rights bitmask */
        public static array $rights = [];
        /** @var int[] entities the session may act on */
        public static array $entities = [0];
        /** @var int[]|null entities the session may only look at (null = same as $entities) */
        public static ?array $viewEntities = null;
        /** @var array<int,array{0:string,1:int}> */
        public static array $messages = [];

        public static function checkLoginUser(): void {}

        public static function getLoginUserID()
        {
            return 7;
        }

        public static function haveRight(string $name, int $right): bool
        {
            return ((self::$rights[$name] ?? 0) & $right) === $right;
        }

        public static function checkRight(string $name, int $right): void
        {
            if (!self::haveRight($name, $right)) {
                throw new \Glpi\Exception\Http\AccessDeniedHttpException('right');
            }
        }

        public static function addMessageAfterRedirect($msg, $check_once = false, $type = INFO, $reset = false): void
        {
            self::$messages[] = [(string) $msg, (int) $type];
        }

        public static function getNewCSRFToken(): string
        {
            return 'csrf';
        }
    }

    final class Html
    {
        public static function redirect(string $url): void
        {
            throw new HarnessRedirect($url);
        }

        public static function header(...$a): void {}

        public static function footer(): void {}

        /** Same parameter type as the core method, so a string argument fails under strict_types. */
        public static function displayMessageAfterRedirect(bool $display_container = true): string
        {
            return '';
        }
    }

    final class Toolbox
    {
        /** @var string[] */
        public static array $lines = [];

        public static function logInFile(string $name, string $text, bool $force = false): bool
        {
            self::$lines[] = $name . ': ' . $text;

            return true;
        }
    }

    final class GLPIKey
    {
        public function encrypt(string $s): string
        {
            return 'enc:' . base64_encode($s);
        }

        public function decrypt(?string $s): string
        {
            $s = (string) $s;

            return str_starts_with($s, 'enc:') ? (string) base64_decode(substr($s, 4)) : '';
        }
    }

    final class ProfileRight
    {
        /** @var string[] */
        public static array $removed = [];

        public static function deleteProfileRights(array $rights): void
        {
            self::$removed = array_merge(self::$removed, $rights);
        }
    }

    final class CronTask
    {
        public const MODE_INTERNAL = 1;
        public const MODE_EXTERNAL = 2;
        public const STATE_WAITING = 1;

        /** @var array<int,array<int,mixed>> */
        public static array $registered = [];
        public int $volume = 0;

        public static function Register(...$a): void
        {
            self::$registered[] = $a;
        }

        public static function Unregister(string $key): void {}

        public function addVolume(int $n): void
        {
            $this->volume += $n;
        }
    }

    /** Result set with the two accessors the plugin uses (foreach + current()). */
    final class HarnessIterator implements \IteratorAggregate, \Countable
    {
        /** @param array<int,array<string,mixed>> $rows */
        public function __construct(private array $rows) {}

        public function getIterator(): \ArrayIterator
        {
            return new \ArrayIterator($this->rows);
        }

        public function current(): ?array
        {
            return $this->rows[0] ?? null;
        }

        public function count(): int
        {
            return count($this->rows);
        }
    }

    /**
     * In-memory DBmysql: tables are arrays of rows; every write and raw query is
     * journalled in $log so a test can prove a refused path wrote nothing.
     */
    class DBmysql
    {
        /** @var array<string,array<int,array<string,mixed>>> */
        public array $tables = [];
        /** @var array<int,array<int,mixed>> */
        public array $log = [];
        private int $lastId = 0;
        /** @var callable|null raw-query handler (sql) => mixed */
        public $onQuery = null;

        public function tableExists(string $t): bool
        {
            return array_key_exists($t, $this->tables);
        }

        public function fieldExists(string $t, string $f): bool
        {
            return true;
        }

        /** @return string[][] */
        public function listTables(): array
        {
            return array_map(static fn (string $t): array => ['TABLE_NAME' => $t], array_keys($this->tables));
        }

        private static function matches(array $row, array $where): bool
        {
            foreach ($where as $col => $cond) {
                $val = $row[$col] ?? null;
                if (is_array($cond) && isset($cond[0]) && in_array($cond[0], ['<', '>', '<=', '>=', '!=', '&'], true) && count($cond) === 2) {
                    $ok = match ($cond[0]) {
                        '&'  => (((int) $val) & (int) $cond[1]) === (int) $cond[1],
                        '<'  => $val < $cond[1],
                        '>'  => $val > $cond[1],
                        '<=' => $val <= $cond[1],
                        '>=' => $val >= $cond[1],
                        '!=' => $val != $cond[1],
                    };
                    if (!$ok) {
                        return false;
                    }
                } elseif (is_array($cond)) {
                    if (!in_array($val, $cond)) {
                        return false;
                    }
                } elseif ($val != $cond) {
                    return false;
                }
            }

            return true;
        }

        public function request(array $q): HarnessIterator
        {
            $from = (string) ($q['FROM'] ?? '');
            $rows = [];
            foreach ($this->tables[$from] ?? [] as $r) {
                if (self::matches($r, (array) ($q['WHERE'] ?? []))) {
                    $rows[] = $r;
                }
            }
            if (($q['ORDER'] ?? '') === 'id DESC') {
                usort($rows, static fn ($a, $b) => (int) $b['id'] <=> (int) $a['id']);
            }
            if (isset($q['LIMIT'])) {
                $rows = array_slice($rows, 0, (int) $q['LIMIT']);
            }

            return new HarnessIterator($rows);
        }

        public function insert(string $t, array $data): bool
        {
            $this->log[] = ['insert', $t, $data];
            if (!isset($data['id'])) {
                $max = 0;
                foreach ($this->tables[$t] ?? [] as $r) {
                    $max = max($max, (int) ($r['id'] ?? 0));
                }
                $data['id'] = $max + 1;
            }
            foreach ($this->uniqueKeys[$t] ?? [] as $cols) {
                foreach ($this->tables[$t] ?? [] as $r) {
                    $dup = true;
                    foreach ($cols as $c) {
                        $dup = $dup && (($r[$c] ?? null) == ($data[$c] ?? null));
                    }
                    if ($dup) {
                        throw new \RuntimeException('Duplicate entry');
                    }
                }
            }
            $this->tables[$t][] = $data;
            $this->lastId = (int) $data['id'];

            return true;
        }

        /** @var array<string,array<int,string[]>> table => list of unique column sets */
        public array $uniqueKeys = [];

        public function insertId(): int
        {
            return $this->lastId;
        }

        public function update(string $t, array $data, array $where): bool
        {
            $this->log[] = ['update', $t, $data, $where];
            foreach ($this->tables[$t] ?? [] as $i => $r) {
                if (self::matches($r, $where)) {
                    $this->tables[$t][$i] = array_merge($r, $data);
                }
            }

            return true;
        }

        public function updateOrInsert(string $t, array $data, array $where): bool
        {
            foreach ($this->tables[$t] ?? [] as $r) {
                if (self::matches($r, $where)) {
                    return $this->update($t, $data, $where);
                }
            }

            return $this->insert($t, array_merge($where, $data));
        }

        public function delete(string $t, array $where): bool
        {
            $this->log[] = ['delete', $t, $where];
            foreach ($this->tables[$t] ?? [] as $i => $r) {
                if (self::matches($r, $where)) {
                    unset($this->tables[$t][$i]);
                }
            }
            $this->tables[$t] = array_values($this->tables[$t] ?? []);

            return true;
        }

        public function doQuery(string $sql)
        {
            $this->log[] = ['query', $sql];
            if (is_callable($this->onQuery)) {
                return ($this->onQuery)($sql);
            }

            return true;
        }

        public function quoteName(string $n): string
        {
            return '`' . str_replace('`', '``', $n) . '`';
        }

        public function quoteValue(string $v): string
        {
            return "'" . addslashes($v) . "'";
        }

        public function fetchAssoc($res): ?array
        {
            return null;
        }
    }

    /**
     * CommonDBTM stand-in: rows live in the fake $DB; can() applies the session
     * right AND the row's entity, as the core does — the thing M-1 is about.
     */
    class CommonGLPI {}

    class CommonDBTM extends CommonGLPI
    {
        public array $fields = [];

        public static function getTable(): string
        {
            return 'glpi_' . strtolower(preg_replace('/^Plugin([A-Z][a-z]+)([A-Z][a-z]+)$/', 'plugin_$1_$2', static::class)) . 's';
        }

        public function getID(): int
        {
            return (int) ($this->fields['id'] ?? 0);
        }

        public function getFromDB($id): bool
        {
            global $DB;
            $row = $DB->request(['FROM' => static::getTable(), 'WHERE' => ['id' => (int) $id], 'LIMIT' => 1])->current();
            $this->fields = $row ?? [];

            return $row !== null;
        }

        public function can($id, int $right, ?array &$input = null): bool
        {
            if (!$this->getFromDB($id)) {
                return false;
            }
            $rightname = static::$rightname ?? '';

            return Session::haveRight((string) $rightname, $right)
                && in_array((int) ($this->fields['entities_id'] ?? 0), Session::$entities, true);
        }

        public function canViewItem(): bool
        {
            return in_array((int) ($this->fields['entities_id'] ?? 0), Session::$viewEntities ?? Session::$entities, true);
        }

        public function canUpdateItem(): bool
        {
            return $this->canViewItem();
        }

        public function prepareInputForAdd($input)
        {
            return $input;
        }

        public function prepareInputForUpdate($input)
        {
            return $input;
        }

        public function add(array $input)
        {
            global $DB;
            $input = $this->prepareInputForAdd($input);
            if ($input === false) {
                return false;
            }
            $DB->insert(static::getTable(), $input);

            return $DB->insertId();
        }

        public function update(array $input): bool
        {
            global $DB;
            if (!$this->getFromDB((int) ($input['id'] ?? 0))) {
                return false;
            }
            $input = $this->prepareInputForUpdate($input);
            if ($input === false) {
                return false;
            }
            $DB->update(static::getTable(), $input, ['id' => (int) $this->fields['id']]);

            return true;
        }

        public function delete(array $input, $force = false): bool
        {
            global $DB;
            $DB->delete(static::getTable(), ['id' => (int) ($input['id'] ?? 0)]);

            return true;
        }
    }

    $GLOBALS['DB']       = new DBmysql();
    $GLOBALS['CFG_GLPI'] = ['root_doc' => ''];
}
