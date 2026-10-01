<?php

declare(strict_types=1);

/**
 * EidCloud Micro Test Runner (Zero-Dependency)
 */

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/MicroFrameworkTest.php';

use EidCloud\Micro\Tests\MicroFrameworkTest;

function testColor(string $text, string $colorCode): string
{
    if (DIRECTORY_SEPARATOR === '\\' && getenv('ANSICON') === false && getenv('WT_SESSION') === null) {
        return $text;
    }
    return "\033[{$colorCode}m{$text}\033[0m";
}

echo "\n" . testColor("⚡ eidcloud-micro Test Suite", "1;36") . "\n";
echo testColor("========================================", "90") . "\n";
echo "PHP Version: " . PHP_VERSION . "\n\n";

$testClass = new ReflectionClass(MicroFrameworkTest::class);
$methods = $testClass->getMethods(ReflectionMethod::IS_PUBLIC);

$testMethods = array_filter(
    $methods,
    fn(ReflectionMethod $m) => str_starts_with($m->getName(), 'test')
);

$passed = 0;
$failed = 0;
$failures = [];

$suiteStart = hrtime(true);

foreach ($testMethods as $method) {
    $testInstance = new MicroFrameworkTest();
    $methodName = $method->getName();

    $testStart = hrtime(true);
    try {
        $method->invoke($testInstance);
        $testDurationMs = (hrtime(true) - $testStart) / 1e+6;

        echo "  " . testColor("✔ PASS", "32") . " " . $methodName . " " . testColor(sprintf("(%.2fms)", $testDurationMs), "90") . "\n";
        $passed++;
    } catch (Throwable $e) {
        $testDurationMs = (hrtime(true) - $testStart) / 1e+6;
        echo "  " . testColor("✖ FAIL", "31") . " " . $methodName . " " . testColor(sprintf("(%.2fms)", $testDurationMs), "90") . "\n";
        $failed++;
        $failures[] = [
            'method' => $methodName,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }
}

$suiteDurationMs = (hrtime(true) - $suiteStart) / 1e+6;

echo "\n" . testColor("----------------------------------------", "90") . "\n";
echo "Results: ";
if ($failed === 0) {
    echo testColor("ALL TESTS PASSED ({$passed}/{$passed})", "1;32");
} else {
    echo testColor("FAILED ({$failed}/" . ($passed + $failed) . ")", "1;31");
}
echo testColor(sprintf(" in %.2fms\n\n", $suiteDurationMs), "90");

if (!empty($failures)) {
    echo testColor("Failures:\n", "1;31");
    foreach ($failures as $idx => $f) {
        echo "  " . ($idx + 1) . ") " . $f['method'] . "\n";
        echo "     " . testColor($f['message'], "31") . "\n";
        echo "     " . testColor("at " . $f['file'] . ":" . $f['line'], "90") . "\n\n";
    }
    exit(1);
}

exit(0);
