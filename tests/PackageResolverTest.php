<?php

/**
 * Unit tests for Opencontent\Installer\PackageResolver.
 *
 * Pure path logic, no eZ Publish bootstrap, no filesystem I/O needed.
 *
 * Usage:
 *   php tests/PackageResolverTest.php
 */

require_once __DIR__ . '/../src/Opencontent/Installer/PackageResolver.php';

use Opencontent\Installer\PackageResolver;

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

// ─────────────────────────────────────────────────────────────────────────────
// Risoluzione 'main' -> root dell'installer principale
// ─────────────────────────────────────────────────────────────────────────────

$resolver = new PackageResolver('/srv/installer');

assert_eq(
    $resolver->resolvePackageDir('main'),
    '/srv/installer',
    "'main' risolve alla root dell'installer principale"
);

// ─────────────────────────────────────────────────────────────────────────────
// Risoluzione nome modulo -> modules/<nome>
// ─────────────────────────────────────────────────────────────────────────────

assert_eq(
    $resolver->resolvePackageDir('trasparenza'),
    '/srv/installer/modules/trasparenza',
    "nome modulo risolve a modules/<nome> sotto la root"
);

assert_eq(
    $resolver->resolvePackageDir('trasparenza-c1'),
    '/srv/installer/modules/trasparenza-c1',
    "nome modulo con trattino risolve correttamente"
);

// ─────────────────────────────────────────────────────────────────────────────
// Root con trailing slash normalizzata
// ─────────────────────────────────────────────────────────────────────────────

$resolverWithTrailingSlash = new PackageResolver('/srv/installer/');

assert_eq(
    $resolverWithTrailingSlash->resolvePackageDir('main'),
    '/srv/installer',
    "root con trailing slash viene normalizzata (main)"
);

assert_eq(
    $resolverWithTrailingSlash->resolvePackageDir('projects'),
    '/srv/installer/modules/projects',
    "root con trailing slash viene normalizzata (modulo)"
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
