<?php

require 'autoload.php';

$script = eZScript::instance([
    'description' => "Install table in dba",
    'use-session' => false,
    'use-modules' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[dba:]',
    '',
    array(
        'dba' => "Dba path"
    )
);
$script->initialize();
$cli = eZCLI::instance();

if ($options['dba']) {

    $ini = eZINI::instance('dbschema.ini');
    $schemaPaths = $ini->variable('SchemaSettings', 'SchemaPaths');
    $schemaPaths['postgresql'] = 'vendor/opencontent/ocinstaller/src/Opencontent/Installer/ezpgsqlschema.php';
    $ini->setVariable('SchemaSettings', 'SchemaPaths', $schemaPaths);


    $db = eZDB::instance();
    $schemaArray = eZDbSchema::read($options['dba'], true);
    $schemaArray['type'] = strtolower($db->databaseName());
    $schemaArray['instance'] = $db;
    $dbSchema = eZDbSchema::instance($schemaArray);
    $params = array(
        'schema' => true,
        'data' => false
    );
    if (!$dbSchema->insertSchema($params)) {
        $cli->error("Unknown error");
    }
}

$script->shutdown();