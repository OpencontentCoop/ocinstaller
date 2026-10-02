<?php

/**
 * Unit tests for Opencontent\Installer\RenameClassAttributeDecision.
 *
 * Pure logic (CC7/CC8 from docs/superpowers/specs/2026-06-30-rename-class-attribute-design.md),
 * no eZ Publish bootstrap needed.
 *
 * Usage:
 *   php tests/RenameClassAttributeDecisionTest.php
 */

require_once __DIR__ . '/../src/Opencontent/Installer/RenameClassAttributeDecision.php';

use Opencontent\Installer\RenameClassAttributeDecision;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

$PASSED = 0;
$FAILED = 0;

function ok(string $name): void    { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_eq($a, $b, string $t, string $r = ''): void
{
    if ($a === $b) {
        ok($t);
    } else {
        fail($t, sprintf("expected %s, got %s. %s", var_export($b, true), var_export($a, true), $r));
    }
}
function assert_true(bool $v, string $t, string $r = ''): void { $v ? ok($t) : fail($t, $r); }

// ─────────────────────────────────────────────────────────────────────────────
// old esiste + new non esiste -> RENAME (caso normale)
// ─────────────────────────────────────────────────────────────────────────────

assert_eq(
    RenameClassAttributeDecision::decide(true, false),
    RenameClassAttributeDecision::RENAME,
    "old esiste, new non esiste -> rename"
);

// ─────────────────────────────────────────────────────────────────────────────
// old non esiste + new esiste -> SKIP silenzioso (già fatto, idempotenza)
// ─────────────────────────────────────────────────────────────────────────────

assert_eq(
    RenameClassAttributeDecision::decide(false, true),
    RenameClassAttributeDecision::SKIP,
    "old non esiste, new esiste -> skip (rename già eseguito in un run precedente)"
);

// ─────────────────────────────────────────────────────────────────────────────
// old esiste + new esiste -> conflitto, errore esplicito
// ─────────────────────────────────────────────────────────────────────────────

$threwForConflict = false;
try {
    RenameClassAttributeDecision::decide(true, true);
} catch (\RuntimeException $e) {
    $threwForConflict = true;
}
assert_true($threwForConflict, "old esiste E new esiste -> RuntimeException (conflitto, non si può decidere da soli)");

// ─────────────────────────────────────────────────────────────────────────────
// né old né new esistono -> stato inatteso, errore esplicito
// ─────────────────────────────────────────────────────────────────────────────

$threwForMissing = false;
try {
    RenameClassAttributeDecision::decide(false, false);
} catch (\RuntimeException $e) {
    $threwForMissing = true;
}
assert_true($threwForMissing, "né old né new esistono -> RuntimeException (stato inatteso)");

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
