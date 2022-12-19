<?php
require 'autoload.php';

use Opencontent\Installer\InstallerSynchronizer;
use Symfony\Component\Yaml\Yaml;

$script = eZScript::instance([
    'description' => "Dump class in yml",
    'use-session' => false,
    'use-modules' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[data:]',
    '',
    array(
        'data' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();

$db = eZDB::instance();
eZDB::setErrorHandling(eZDB::ERROR_HANDLING_EXCEPTIONS);

try {
    $dataDir = $options['data'];
    if ($dataDir && !is_dir($dataDir)) {
        throw new Exception("Directory $dataDir not found");
    }

    $synchronizer = new InstallerSynchronizer($db, $dataDir);
    $synchronizer->sync($options);

} catch (Throwable $e) {
    if ($e instanceof eZDBException) {
        if ($db->TransactionCounter > 0) {
            $db->rollback();
        }
    }
    if ($e instanceof \Symfony\Component\Yaml\Exception\ParseException) {
        $cli->error($e->getMessage() . ' at line ' . $e->getParsedLine() . ' of ' . $e->getParsedFile());
    } else {
        $cli->error($e->getMessage());
    }

    $cli->error('At line ' . $e->getLine() . ' of ' . $e->getFile());
    $cli->output($e->getTraceAsString());
}

$script->shutdown();