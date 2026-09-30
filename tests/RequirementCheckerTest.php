<?php

/**
 * Unit tests for Opencontent\Installer\RequirementChecker.
 *
 * Pure logic, no eZ Publish bootstrap needed.
 *
 * Usage:
 *   php tests/RequirementCheckerTest.php
 */

require_once __DIR__ . '/../src/Opencontent/Installer/RequirementChecker.php';

use Opencontent\Installer\RequirementChecker;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

$PASSED = 0;
$FAILED = 0;

function ok(string $name): void    { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_true(bool $v, string $t, string $r = ''): void  { $v ? ok($t) : fail($t, $r); }
function assert_false(bool $v, string $t, string $r = ''): void { (!$v) ? ok($t) : fail($t, $r); }

// ─────────────────────────────────────────────────────────────────────────────
// Vincolo a soglia (">=X")
// ─────────────────────────────────────────────────────────────────────────────

assert_true(
    RequirementChecker::isSatisfied('3.2.0', '3.2.0', '>=3.2.0'),
    'floor: versione corrente uguale alla soglia soddisfa >=X'
);
assert_true(
    RequirementChecker::isSatisfied('4.0.0', '3.2.0', '>=3.2.0'),
    'floor: versione corrente maggiore della soglia soddisfa >=X'
);
assert_false(
    RequirementChecker::isSatisfied('3.1.9', '3.2.0', '>=3.2.0'),
    'floor: versione corrente minore della soglia NON soddisfa >=X'
);
assert_false(
    RequirementChecker::isSatisfied('0.0.0', '3.2.0', '>=3.2.0'),
    'floor: dipendenza mai installata (0.0.0) NON soddisfa >=X'
);

// ─────────────────────────────────────────────────────────────────────────────
// Vincolo "latest"
// ─────────────────────────────────────────────────────────────────────────────

assert_true(
    RequirementChecker::isSatisfied('3.8.9', '3.8.9', 'latest'),
    'latest: versione corrente uguale a quella dichiarata dalla dipendenza soddisfa latest'
);
assert_false(
    RequirementChecker::isSatisfied('3.7.0', '3.8.9', 'latest'),
    'latest: versione corrente indietro rispetto a quella dichiarata NON soddisfa latest'
);
assert_false(
    RequirementChecker::isSatisfied('3.9.0', '3.8.9', 'latest'),
    'latest: versione corrente avanti rispetto a quella dichiarata NON soddisfa latest (deve combaciare esattamente)'
);
assert_false(
    RequirementChecker::isSatisfied('0.0.0', '3.8.9', 'latest'),
    'latest: dipendenza mai installata (0.0.0) NON soddisfa latest'
);

// ─────────────────────────────────────────────────────────────────────────────
// Vincolo non valido
// ─────────────────────────────────────────────────────────────────────────────

$threwForInvalidConstraint = false;
try {
    RequirementChecker::isSatisfied('1.0.0', '1.0.0', 'not-a-valid-constraint');
} catch (\InvalidArgumentException $e) {
    $threwForInvalidConstraint = true;
}
assert_true(
    $threwForInvalidConstraint,
    'vincolo malformato solleva InvalidArgumentException invece di fallire silenziosamente'
);

// ─────────────────────────────────────────────────────────────────────────────
// Results
// ─────────────────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) {
    echo ", \033[31m{$FAILED} failed\033[0m";
}
echo "\n";

exit($FAILED > 0 ? 1 : 0);
