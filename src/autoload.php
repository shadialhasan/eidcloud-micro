<?php

declare(strict_types=1);

/**
 * Built-in zero-dependency PSR-4 autoloader for EidCloud Micro.
 * Allows running tests, CLI commands, and microservices without requiring `composer install`.
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'EidCloud\\Micro\\';
    $baseDir = __DIR__ . '/';

    if (str_starts_with($class, $prefix)) {
        $relativeClass = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }

    $testPrefix = 'EidCloud\\Micro\\Tests\\';
    $testBaseDir = dirname(__DIR__) . '/tests/';

    if (str_starts_with($class, $testPrefix)) {
        $relativeClass = substr($class, strlen($testPrefix));
        $file = $testBaseDir . str_replace('\\', '/', $relativeClass) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    }
});
