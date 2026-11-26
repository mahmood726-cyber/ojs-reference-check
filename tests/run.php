<?php

/**
 * Minimal runner for the unit tests, for machines without PHPUnit:
 *
 *     php tests/run.php
 *
 * It supports only what these tests use. Exit code 0 means every case passed.
 */

namespace PHPUnit\Framework\Attributes {
    if (!class_exists(DataProvider::class)) {
        #[\Attribute(\Attribute::TARGET_METHOD)]
        class DataProvider
        {
            public function __construct(public string $method) {}
        }
    }
}

namespace PHPUnit\Framework {
    class TestCase
    {
        public static int $assertions = 0;

        public static function assertSame(mixed $expected, mixed $actual): void
        {
            self::$assertions++;
            if ($expected !== $actual) {
                throw new \AssertionError(
                    'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
                );
            }
        }

        public static function assertCount(int $expected, array $actual): void
        {
            self::assertSame($expected, count($actual));
        }

        public static function assertIsArray(mixed $actual): void
        {
            self::assertSame(true, is_array($actual));
        }
    }
}

namespace {
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    error_reporting(E_ALL);
    set_error_handler(function (int $level, string $message, string $file, int $line): bool {
        throw new \ErrorException($message, 0, $level, $file, $line);
    });
    // Expected request failures are logged by the plugin; keep the output readable
    ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

    require __DIR__ . '/bootstrap.php';

    $passed = 0;
    $failed = 0;
    foreach (glob(__DIR__ . '/unit/*Test.php') as $file) {
        require $file;
        $class = 'APP\\plugins\\generic\\referenceCheck\\tests\\unit\\' . basename($file, '.php');
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $class || !str_starts_with($method->name, 'test')) {
                continue;
            }
            $providers = $method->getAttributes(DataProvider::class);
            $cases = $providers ? [$class, $providers[0]->newInstance()->method]() : [[]];
            foreach ($cases as $name => $arguments) {
                try {
                    $before = TestCase::$assertions;
                    $method->invokeArgs(new $class(), array_values($arguments));
                    if (TestCase::$assertions === $before) {
                        throw new AssertionError('made no assertion');
                    }
                    $passed++;
                } catch (Throwable $e) {
                    $failed++;
                    echo "FAIL {$method->class}::{$method->name} [{$name}]: {$e->getMessage()}\n";
                }
            }
        }
    }

    echo "{$passed} passed, {$failed} failed, " . TestCase::$assertions . " assertions\n";
    exit($failed === 0 && $passed > 0 ? 0 : 1);
}
