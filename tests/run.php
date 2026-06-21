<?php
/**
 * Standalone PHP-shim test runner for the PURE-logic tests — PHPUnit is not
 * installed in this tree, so this defines a minimal PHPUnit\Framework\TestCase
 * (only the assertions the suite uses) and runs every tests/*Test.php class's
 * test* methods. On a box that HAS PHPUnit, just run `phpunit tests/` instead —
 * the test files are unchanged real PHPUnit cases.
 *
 *   php tests/run.php
 *
 * @license GPL-2.0-or-later
 * @copyright 2026 Convergent Cloud Computing
 */

declare(strict_types=1);

namespace PHPUnit\Framework {
    class AssertionFailedError extends \Exception {}

    class TestCase
    {
        /** @var class-string|null expected-exception class for the running test */
        public ?string $__expectedException = null;

        /** PHPUnit-compatible: declare the test expects this exception to be thrown. */
        public function expectException(string $class): void
        {
            $this->__expectedException = $class;
        }

        private static function fail(string $msg): void
        {
            throw new AssertionFailedError($msg);
        }

        private static function ex($v): string
        {
            return var_export($v, true);
        }

        public static function assertSame($expected, $actual, string $m = ''): void
        {
            if ($expected !== $actual) {
                self::fail("assertSame: expected " . self::ex($expected) . " got " . self::ex($actual) . " $m");
            }
        }

        public static function assertTrue($c, string $m = ''): void
        {
            if ($c !== true) {
                self::fail("assertTrue: got " . self::ex($c) . " $m");
            }
        }

        public static function assertFalse($c, string $m = ''): void
        {
            if ($c !== false) {
                self::fail("assertFalse: got " . self::ex($c) . " $m");
            }
        }

        public static function assertNull($c, string $m = ''): void
        {
            if ($c !== null) {
                self::fail("assertNull: got " . self::ex($c) . " $m");
            }
        }

        public static function assertIsArray($c, string $m = ''): void
        {
            if (!is_array($c)) {
                self::fail("assertIsArray: got " . self::ex($c) . " $m");
            }
        }

        public static function assertContains($needle, $haystack, string $m = ''): void
        {
            if (!is_array($haystack) || !in_array($needle, $haystack, true)) {
                self::fail("assertContains: " . self::ex($needle) . " not in " . self::ex($haystack) . " $m");
            }
        }

        public static function assertStringContainsString(string $needle, string $haystack, string $m = ''): void
        {
            if (!str_contains($haystack, $needle)) {
                self::fail("assertStringContainsString: " . self::ex($needle) . " not in " . self::ex($haystack) . " $m");
            }
        }

        public static function assertStringNotContainsString(string $needle, string $haystack, string $m = ''): void
        {
            if (str_contains($haystack, $needle)) {
                self::fail("assertStringNotContainsString: " . self::ex($needle) . " IS in " . self::ex($haystack) . " $m");
            }
        }
    }
}

namespace {
    $dir   = __DIR__;
    $files = glob($dir . '/*Test.php') ?: [];

    $before = get_declared_classes();
    foreach ($files as $f) {
        require_once $f;
    }
    $after = get_declared_classes();
    $testClasses = array_filter(
        array_diff($after, $before),
        static fn (string $c): bool => str_ends_with($c, 'Test')
    );

    $pass = 0;
    $fail = 0;
    $fails = [];
    foreach ($testClasses as $class) {
        $obj = new $class();
        foreach (get_class_methods($class) as $method) {
            if (!str_starts_with($method, 'test')) {
                continue;
            }
            $obj->__expectedException = null;
            try {
                $obj->$method();
                if ($obj->__expectedException !== null) {
                    $fail++;
                    $fails[] = "$class::$method — expected {$obj->__expectedException} but none thrown";
                } else {
                    $pass++;
                }
            } catch (\Throwable $e) {
                $expected = $obj->__expectedException;
                if ($expected !== null && ($e instanceof $expected)) {
                    $pass++;
                } else {
                    $fail++;
                    $fails[] = "$class::$method — " . $e->getMessage();
                }
            }
        }
    }

    echo "Tests: " . ($pass + $fail) . "  Passed: $pass  Failed: $fail\n";
    foreach ($fails as $line) {
        echo "  FAIL: $line\n";
    }
    exit($fail === 0 ? 0 : 1);
}
