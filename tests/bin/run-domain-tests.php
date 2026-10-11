<?php

declare(strict_types=1);

/**
 * Dependency-free runner for tests/Unit/Domain.
 *
 * The domain layer (app/Domain) has no framework dependencies, so its tests can run even
 * where Composer packages are unavailable. When PHPUnit is installed these exact files run
 * under it unchanged; this runner only supplies a minimal TestCase if PHPUnit is absent.
 *
 * Usage: php tests/bin/run-domain-tests.php
 */
$root = dirname(__DIR__, 2);

spl_autoload_register(function (string $class) use ($root): void {
    $map = ['App\\' => $root.'/app/', 'Tests\\' => $root.'/tests/'];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});

if (is_file($root.'/vendor/autoload.php')) {
    require $root.'/vendor/autoload.php';
}

if (! class_exists(\PHPUnit\Framework\TestCase::class)) {
    require __DIR__.'/MiniTestCase.php';
}

$files = new RegexIterator(
    new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/tests/Unit/Domain')),
    '/Test\.php$/'
);

$passed = 0;
$failed = [];
foreach ($files as $file) {
    require_once $file->getPathname();
}

foreach (get_declared_classes() as $class) {
    if (! str_starts_with($class, 'Tests\\Unit\\Domain\\') || ! is_subclass_of($class, \PHPUnit\Framework\TestCase::class)) {
        continue;
    }
    foreach (get_class_methods($class) as $method) {
        if (! str_starts_with($method, 'test')) {
            continue;
        }
        $label = substr($class, strlen('Tests\\Unit\\Domain\\')).'::'.$method;
        $test = new $class($method);
        try {
            $test->runBare();
            $passed++;
            echo "  ✓ {$label}\n";
        } catch (Throwable $e) {
            $failed[] = $label;
            echo "  ✗ {$label}\n      ".get_class($e).': '.$e->getMessage()."\n";
        }
    }
}

echo "\n".$passed.' passed, '.count($failed)." failed\n";
exit($failed === [] ? 0 : 1);
