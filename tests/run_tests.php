<?php

/**
 * Test runner — executes all test files and aggregates results.
 *
 * Usage:
 *   php tests/run_tests.php
 */

$testFiles = [
    __DIR__ . '/RequirementCheckerTest.php',
    __DIR__ . '/PackageResolverTest.php',
    __DIR__ . '/PackageManifestReaderTest.php',
    __DIR__ . '/RenameClassAttributeDecisionTest.php',
    __DIR__ . '/ReindexTest.php',
];

$allPassed = true;
$totalPass = 0;
$totalFail = 0;

foreach ($testFiles as $file) {
    $name = basename($file);
    echo "\n" . str_repeat('═', 50) . "\n";
    echo "Running: $name\n";
    echo str_repeat('─', 50) . "\n";

    $output   = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);

    echo implode("\n", $output) . "\n";

    foreach ($output as $line) {
        if (preg_match('/(\d+) passed/', $line, $m)) {
            $totalPass += (int)$m[1];
        }
        if (preg_match('/(\d+) failed/', $line, $m)) {
            $totalFail += (int)$m[1];
        }
    }

    if ($exitCode !== 0) {
        $allPassed = false;
        echo "\033[31m[FAILED] $name exited with code $exitCode\033[0m\n";
    } else {
        echo "\033[32m[OK] $name\033[0m\n";
    }
}

$total = $totalPass + $totalFail;
$pct   = $total > 0 ? round($totalPass / $total * 100, 1) : 0.0;

echo "\n" . str_repeat('═', 50) . "\n";
echo $allPassed
    ? "\033[32m✓ All test suites passed\033[0m\n"
    : "\033[31m✗ One or more test suites FAILED\033[0m\n";

echo "Tests passed: {$pct}%\n";

exit($allPassed ? 0 : 1);
