<?php

/**
 * Unit tests for Opencontent\Installer\PackageManifestReader.
 *
 * Real file I/O against temp fixture files, no eZ Publish bootstrap.
 *
 * Usage:
 *   php tests/PackageManifestReaderTest.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Opencontent/Installer/PackageManifestReader.php';

use Opencontent\Installer\PackageManifestReader;

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

function makeFixtureDir(string $installerYmlContent = null): string
{
    $dir = sys_get_temp_dir() . '/ocinstaller_test_' . uniqid();
    mkdir($dir, 0777, true);
    if ($installerYmlContent !== null) {
        file_put_contents($dir . '/installer.yml', $installerYmlContent);
    }
    return $dir;
}

function removeDir(string $dir): void
{
    foreach (glob($dir . '/*') as $file) {
        unlink($file);
    }
    rmdir($dir);
}

// ─────────────────────────────────────────────────────────────────────────────
// Lettura manifest valido
// ─────────────────────────────────────────────────────────────────────────────

$validDir = makeFixtureDir("name: 'OpenCity Trasparenza C1'\nversion: 0.3.0\n");
$manifest = PackageManifestReader::read($validDir);
assert_eq($manifest['name'], 'OpenCity Trasparenza C1', 'legge correttamente il campo name');
assert_eq($manifest['version'], '0.3.0', 'legge correttamente il campo version');
removeDir($validDir);

// ─────────────────────────────────────────────────────────────────────────────
// Manifest mancante
// ─────────────────────────────────────────────────────────────────────────────

$missingDir = makeFixtureDir(null);
$threwForMissingManifest = false;
try {
    PackageManifestReader::read($missingDir);
} catch (\RuntimeException $e) {
    $threwForMissingManifest = true;
}
assert_true($threwForMissingManifest, "manifest mancante solleva RuntimeException invece di fallire silenziosamente");
rmdir($missingDir);

// ─────────────────────────────────────────────────────────────────────────────
// Manifest senza version
// ─────────────────────────────────────────────────────────────────────────────

$incompleteDir = makeFixtureDir("name: 'OpenCity Something'\n");
$threwForIncompleteManifest = false;
try {
    PackageManifestReader::read($incompleteDir);
} catch (\RuntimeException $e) {
    $threwForIncompleteManifest = true;
}
assert_true($threwForIncompleteManifest, "manifest senza 'version' solleva RuntimeException");
removeDir($incompleteDir);

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
