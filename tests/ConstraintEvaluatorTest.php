<?php

/**
 * Unit tests for Opencontent\Installer\ConstraintEvaluator.
 *
 * Real file I/O against temp fixture manifests, no eZ Publish bootstrap.
 *
 * Usage:
 *   php tests/ConstraintEvaluatorTest.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Opencontent/Installer/PackageResolver.php';
require_once __DIR__ . '/../src/Opencontent/Installer/PackageManifestReader.php';
require_once __DIR__ . '/../src/Opencontent/Installer/RequirementChecker.php';
require_once __DIR__ . '/../src/Opencontent/Installer/ConflictChecker.php';
require_once __DIR__ . '/../src/Opencontent/Installer/ConstraintEvaluator.php';

use Opencontent\Installer\ConstraintEvaluator;

$PASSED = 0;
$FAILED = 0;

function ok(string $name): void    { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_eq($a, $b, string $t): void
{
    $a === $b ? ok($t) : fail($t, 'got ' . var_export($a, true) . ', expected ' . var_export($b, true));
}

// Fixture: <root>/installer.yml (main 2.0.0), modules/base 1.0.0, modules/alt-a 1.0.0, modules/alt-b 1.0.0
$root = sys_get_temp_dir() . '/constraint_eval_' . uniqid();
foreach (['' => ['Main', '2.0.0'], '/modules/base' => ['Base', '1.0.0'], '/modules/alt-a' => ['Alt A', '1.0.0'], '/modules/alt-b' => ['Alt B', '1.0.0']] as $dir => [$n, $v]) {
    mkdir($root . $dir, 0777, true);
    file_put_contents($root . $dir . '/installer.yml', "name: '$n'\nversion: $v\n");
}

$applied = function (array $map): callable {
    return function (string $package, string $name) use ($map): string {
        return $map[$package] ?? '0.0.0';
    };
};

$module = [
    'name' => 'Module',
    'requires' => [['package' => 'base', 'version' => 'latest']],
    'conflicts' => ['alt-b'],
];

assert_eq(ConstraintEvaluator::evaluate(['name' => 'X'], $root, $applied([])), [], 'nessun vincolo: nessuna violazione');

assert_eq(
    ConstraintEvaluator::evaluate($module, $root, $applied(['base' => '1.0.0'])),
    [],
    'requires latest soddisfatto e nessun conflitto installato: nessuna violazione'
);

$v = ConstraintEvaluator::evaluate($module, $root, $applied([]));
assert_eq(count($v), 1, 'requires non installato: una violazione');
assert_eq($v[0]['kind'], 'requires', 'la violazione è di tipo requires');
assert_eq($v[0]['package_name'], 'Base', 'package_name letto dal manifest');
assert_eq($v[0]['current'], '0.0.0', 'versione corrente 0.0.0');

$v = ConstraintEvaluator::evaluate($module, $root, $applied(['base' => '0.9.0']));
assert_eq($v[0]['kind'] ?? null, 'requires', 'requires latest con versione indietro: violazione');

$v = ConstraintEvaluator::evaluate($module, $root, $applied(['base' => '1.0.0', 'alt-b' => '1.0.0']));
assert_eq(count($v), 1, 'conflitto installato: una violazione');
assert_eq($v[0]['kind'], 'conflicts', 'la violazione è di tipo conflicts');
assert_eq($v[0]['package_name'], 'Alt B', 'package_name del conflitto dal manifest');

$v = ConstraintEvaluator::evaluate($module, $root, $applied(['alt-b' => '1.0.0']));
assert_eq(array_column($v, 'kind'), ['requires', 'conflicts'], 'requires prima dei conflicts');

$v = ConstraintEvaluator::evaluate(
    ['name' => 'M', 'requires' => [['package' => 'main', 'version' => '>=2.0.0']]],
    $root,
    $applied(['main' => '2.1.0'])
);
assert_eq($v, [], 'requires su main con operatore >= soddisfatto');

$v = ConstraintEvaluator::evaluate(
    ['name' => 'M', 'requires' => [['package' => 'main', 'version' => '>=2.0.0']]],
    $root,
    $applied(['main' => '1.9.0'])
);
assert_eq(count($v), 1, 'requires su main con operatore >= non soddisfatto');

$v = ConstraintEvaluator::evaluate(['name' => 'M', 'requires' => [['package' => 'ghost', 'version' => 'latest']]], $root, $applied([]));
assert_eq(count($v), 1, 'requires su pacchetto inesistente: violazione, nessuna eccezione');

$v = ConstraintEvaluator::evaluate(['name' => 'M', 'conflicts' => ['ghost']], $root, $applied([]));
assert_eq(count($v), 1, 'conflicts su pacchetto inesistente: violazione, nessuna eccezione');

$v = ConstraintEvaluator::evaluate(['name' => 'M', 'requires' => [['package' => 'base', 'version' => 'bogus']]], $root, $applied(['base' => '1.0.0']));
assert_eq(count($v), 1, 'vincolo di versione non valido: violazione, nessuna eccezione');

exec('rm -rf ' . escapeshellarg($root));

echo "\n";
echo str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) {
    echo ", \033[31m{$FAILED} failed\033[0m";
}
echo "\n";

exit($FAILED > 0 ? 1 : 0);
