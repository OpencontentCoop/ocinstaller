<?php

/**
 * Unit tests for Opencontent\Installer\ConflictChecker.
 *
 * Pure logic, no eZ Publish bootstrap needed.
 *
 * Usage:
 *   php tests/ConflictCheckerTest.php
 */

require_once __DIR__ . '/../src/Opencontent/Installer/ConflictChecker.php';

use Opencontent\Installer\ConflictChecker;

$PASSED = 0;
$FAILED = 0;

function ok(string $name): void    { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_true(bool $v, string $t, string $r = ''): void  { $v ? ok($t) : fail($t, $r); }
function assert_false(bool $v, string $t, string $r = ''): void { (!$v) ? ok($t) : fail($t, $r); }

assert_false(ConflictChecker::isInstalled('0.0.0'), "'0.0.0' (mai installato) non è considerato installato");
assert_true(ConflictChecker::isInstalled('0.0.1'), "'0.0.1' è considerato installato");
assert_true(ConflictChecker::isInstalled('0.3.0'), "'0.3.0' è considerato installato");
assert_true(ConflictChecker::isInstalled('3.9.3'), "'3.9.3' è considerato installato");

echo "\n";
echo str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) {
    echo ", \033[31m{$FAILED} failed\033[0m";
}
echo "\n";

exit($FAILED > 0 ? 1 : 0);
