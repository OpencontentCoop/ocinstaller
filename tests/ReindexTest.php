<?php

/**
 * Unit tests for Opencontent\Installer\Reindex.
 *
 * The eZ Publish classes used by the step (eZContentClass, eZContentObject, eZPersistentObject, eZSolr)
 * are replaced by minimal stubs, so no eZ bootstrap is needed. The eZContentObject stub reproduces
 * the in-memory object cache of the real kernel (fetch() fills it, clearCache() empties it): this is
 * what makes the memory test meaningful.
 *
 * Usage:
 *   php tests/ReindexTest.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Opencontent/Installer/InterfaceStepInstaller.php';
require_once __DIR__ . '/../src/Opencontent/Installer/AbstractStepInstaller.php';
require_once __DIR__ . '/../src/Opencontent/Installer/Reindex.php';

// ─────────────────────────────────────────────────────────────────────────────
// eZ Publish stubs
// ─────────────────────────────────────────────────────────────────────────────

class ReindexTestState
{
    /** @var int[] ids returned by the published-objects query */
    public static $ids = [];
    /** @var int[] ids for which fetch() returns null */
    public static $missing = [];
    /** @var int[] ids for which addObject() returns false */
    public static $failing = [];
    public static $payloadBytes = 0;

    public static $fetchObjectListArgs = null;
    public static $fetched = [];
    public static $added = [];
    public static $addCommitArgs = [];
    public static $commitCalls = 0;
    public static $clearCacheCalls = 0;
    public static $cache = [];
    public static $maxCacheSize = 0;
    public static $maxMemory = 0;
    public static $classExists = true;

    public static function reset(): void
    {
        self::$ids = [];
        self::$missing = [];
        self::$failing = [];
        self::$payloadBytes = 0;
        self::$fetchObjectListArgs = null;
        self::$fetched = [];
        self::$added = [];
        self::$addCommitArgs = [];
        self::$commitCalls = 0;
        self::$clearCacheCalls = 0;
        self::$cache = [];
        self::$maxCacheSize = 0;
        self::$maxMemory = 0;
        self::$classExists = true;
    }
}

class eZContentClass
{
    public static function fetchByIdentifier($identifier)
    {
        return ReindexTestState::$classExists ? new self() : false;
    }

    public function attribute($name)
    {
        return 42;
    }
}

class eZContentObject
{
    const STATUS_PUBLISHED = 1;

    public $id;
    public $payload;

    public static function definition()
    {
        return ['class_name' => 'eZContentObject'];
    }

    public static function fetch($id)
    {
        ReindexTestState::$fetched[] = $id;
        if (in_array($id, ReindexTestState::$missing, true)) {
            return null;
        }
        $object = new self();
        $object->id = $id;
        $object->payload = str_repeat(chr(65 + $id % 26), ReindexTestState::$payloadBytes);
        // like the real kernel: every fetched object stays in the in-memory cache until clearCache()
        ReindexTestState::$cache[$id] = $object;
        ReindexTestState::$maxCacheSize = max(ReindexTestState::$maxCacheSize, count(ReindexTestState::$cache));

        return $object;
    }

    public static function clearCache($idArray = [])
    {
        ReindexTestState::$clearCacheCalls++;
        ReindexTestState::$cache = [];
    }
}

class eZPersistentObject
{
    public static function fetchObjectList($def, $fields, $conds, $sorts, $limit, $asObject)
    {
        ReindexTestState::$fetchObjectListArgs = func_get_args();
        $rows = [];
        foreach (ReindexTestState::$ids as $id) {
            $rows[] = ['id' => (string)$id]; // the DB layer returns strings
        }

        return $rows;
    }
}

class eZSolr
{
    public function addObject($object, $commit = true)
    {
        ReindexTestState::$added[] = $object->id;
        ReindexTestState::$addCommitArgs[] = $commit;
        ReindexTestState::$maxMemory = max(ReindexTestState::$maxMemory, memory_get_usage());

        return !in_array($object->id, ReindexTestState::$failing, true);
    }

    public function commit($softCommit = false)
    {
        ReindexTestState::$commitCalls++;
    }
}

class ReindexTestLogger extends \Psr\Log\AbstractLogger
{
    public $records = [];

    public function log($level, $message, array $context = [])
    {
        $this->records[] = [$level, (string)$message];
    }

    public function has(string $level, string $needle): bool
    {
        foreach ($this->records as list($l, $m)) {
            if ($l === $level && strpos($m, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

$PASSED = 0;
$FAILED = 0;

function ok(string $name): void    { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_eq($a, $b, string $t): void
{
    if ($a === $b) {
        ok($t);
    } else {
        fail($t, sprintf("expected %s, got %s", var_export($b, true), var_export($a, true)));
    }
}
function assert_true(bool $v, string $t, string $r = ''): void { $v ? ok($t) : fail($t, $r); }

/**
 * @return array [Reindex, ReindexTestLogger]
 */
function newStep(array $step = []): array
{
    $logger = new ReindexTestLogger();
    $reindex = new \Opencontent\Installer\Reindex();
    $reindex->setLogger($logger);
    $reindex->setStep($step + ['type' => 'reindex', 'identifier' => 'document']);

    return [$reindex, $logger];
}

// ─────────────────────────────────────────────────────────────────────────────
// Query: only published objects of the class, ids only, deterministic order
// ─────────────────────────────────────────────────────────────────────────────

ReindexTestState::reset();
ReindexTestState::$ids = [3, 1, 2];
list($reindex, $logger) = newStep();
$reindex->install();

$args = ReindexTestState::$fetchObjectListArgs;
assert_eq($args[1], ['id'], "la query carica solo la colonna id");
assert_eq($args[2], ['contentclass_id' => 42, 'status' => eZContentObject::STATUS_PUBLISHED], "la query filtra classe e stato published");
assert_eq($args[3], ['id' => 'asc'], "la query ordina per id");
assert_eq($args[5], false, "la query non istanzia oggetti (asObject = false)");
assert_true($logger->has('info', 'Reindex document objects: 3'), "log del totale oggetti");

// ─────────────────────────────────────────────────────────────────────────────
// Every object is indexed once, in order, without per-object commit
// ─────────────────────────────────────────────────────────────────────────────

assert_eq(ReindexTestState::$added, [3, 1, 2], "tutti gli oggetti vengono indicizzati, nell'ordine restituito dalla query");
assert_eq(array_values(array_unique(ReindexTestState::$addCommitArgs)), [false], "addObject non fa commit per singolo oggetto");

// ─────────────────────────────────────────────────────────────────────────────
// Batching: one commit and one clearCache per batch (default 50)
// ─────────────────────────────────────────────────────────────────────────────

ReindexTestState::reset();
ReindexTestState::$ids = range(1, 120);
list($reindex) = newStep();
$reindex->install();
assert_eq(count(ReindexTestState::$added), 120, "120 oggetti: tutti indicizzati");
assert_eq(ReindexTestState::$commitCalls, 3, "120 oggetti, batch 50: 3 commit (50+50+20)");
assert_eq(ReindexTestState::$clearCacheCalls, 3, "120 oggetti, batch 50: 3 clearCache");
assert_eq(ReindexTestState::$maxCacheSize, 50, "la cache non supera mai la dimensione del batch");
assert_eq(ReindexTestState::$cache, [], "a fine step la cache è vuota");

ReindexTestState::reset();
ReindexTestState::$ids = range(1, 100);
list($reindex) = newStep();
$reindex->install();
assert_eq(ReindexTestState::$commitCalls, 2, "100 oggetti, batch 50: esattamente 2 commit (nessun batch vuoto)");

ReindexTestState::reset();
ReindexTestState::$ids = [7];
list($reindex) = newStep();
$reindex->install();
assert_eq(ReindexTestState::$added, [7], "un solo oggetto: indicizzato");
assert_eq(ReindexTestState::$commitCalls, 1, "un solo oggetto: un commit");

// ─────────────────────────────────────────────────────────────────────────────
// batch_size step parameter
// ─────────────────────────────────────────────────────────────────────────────

ReindexTestState::reset();
ReindexTestState::$ids = range(1, 25);
list($reindex) = newStep(['batch_size' => 10]);
$reindex->install();
assert_eq(ReindexTestState::$commitCalls, 3, "batch_size=10 su 25 oggetti: 3 commit");
assert_eq(ReindexTestState::$maxCacheSize, 10, "batch_size=10: la cache non supera 10 oggetti");

foreach ([0, -5, 'abc', null] as $invalid) {
    ReindexTestState::reset();
    ReindexTestState::$ids = range(1, 60);
    list($reindex) = newStep(['batch_size' => $invalid]);
    $reindex->install();
    assert_eq(ReindexTestState::$commitCalls, 2, "batch_size non valido (" . var_export($invalid, true) . "): fallback a 50");
}

// ─────────────────────────────────────────────────────────────────────────────
// Empty class
// ─────────────────────────────────────────────────────────────────────────────

ReindexTestState::reset();
list($reindex, $logger) = newStep();
$reindex->install();
assert_eq(ReindexTestState::$added, [], "nessun oggetto: niente indicizzazione");
assert_eq(ReindexTestState::$commitCalls, 0, "nessun oggetto: nessun commit");
assert_true($logger->has('info', 'objects: 0'), "nessun oggetto: il log riporta 0");

// ─────────────────────────────────────────────────────────────────────────────
// Unknown class
// ─────────────────────────────────────────────────────────────────────────────

ReindexTestState::reset();
ReindexTestState::$classExists = false;
list($reindex) = newStep();
$threw = false;
try {
    $reindex->install();
} catch (\Exception $e) {
    $threw = strpos($e->getMessage(), 'Class document not found') !== false;
}
assert_true($threw, "install: classe inesistente -> Exception");

$threw = false;
try {
    $reindex->dryRun();
} catch (\Exception $e) {
    $threw = strpos($e->getMessage(), 'Class document not found') !== false;
}
assert_true($threw, "dryRun: classe inesistente -> Exception");

ReindexTestState::reset();
ReindexTestState::$ids = [1, 2];
list($reindex, $logger) = newStep();
$reindex->dryRun();
assert_eq(ReindexTestState::$added, [], "dryRun non indicizza nulla");
assert_eq(ReindexTestState::$fetchObjectListArgs, null, "dryRun non interroga gli oggetti");

// ─────────────────────────────────────────────────────────────────────────────
// Missing objects and indexing failures: skipped and reported, the run goes on
// ─────────────────────────────────────────────────────────────────────────────

ReindexTestState::reset();
ReindexTestState::$ids = range(1, 10);
ReindexTestState::$missing = [3];
ReindexTestState::$failing = [5, 8];
list($reindex, $logger) = newStep(['batch_size' => 4]);
$reindex->install();
assert_eq(ReindexTestState::$added, [1, 2, 4, 5, 6, 7, 8, 9, 10], "oggetto non trovato: saltato, gli altri vengono indicizzati");
assert_eq(ReindexTestState::$commitCalls, 3, "con oggetti saltati o falliti i commit restano uno per batch");
assert_true($logger->has('warning', 'not found: 3'), "oggetto non trovato: warning con l'id");
assert_true($logger->has('warning', 'failed for objects: 5, 8'), "addObject false: warning con gli id");

ReindexTestState::reset();
ReindexTestState::$ids = range(1, 5);
list($reindex, $logger) = newStep();
$reindex->install();
$warnings = array_filter($logger->records, function ($r) { return $r[0] === 'warning'; });
assert_eq(count($warnings), 0, "caso normale: nessun warning");

// ─────────────────────────────────────────────────────────────────────────────
// Memory: 6663 objects (size of the real case) of ~64 KB each would need ~430 MB
// if kept in memory; with batches the footprint must stay small.
// ─────────────────────────────────────────────────────────────────────────────

ReindexTestState::reset();
ReindexTestState::$ids = range(1, 6663);
ReindexTestState::$payloadBytes = 64 * 1024;
list($reindex) = newStep();
$baseline = memory_get_usage();
$reindex->install();
$growthMb = (ReindexTestState::$maxMemory - $baseline) / 1048576;
assert_eq(count(ReindexTestState::$added), 6663, "6663 oggetti da 64 KB: tutti indicizzati");
assert_true(
    $growthMb < 20,
    "6663 oggetti da 64 KB: crescita memoria < 20 MB",
    sprintf("crescita %.1f MB", $growthMb)
);
assert_eq(ReindexTestState::$commitCalls, 134, "6663 oggetti: 134 commit (ceil(6663/50))");

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
