<?php

require 'autoload.php';

$script = eZScript::instance([
    'description' => "Dump table in dba",
    'use-session' => false,
    'use-modules' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[tables:]',
    '',
    array(
        'tables' => "Comma separates table names"
    )
);
$script->initialize();
$cli = eZCLI::instance();

if ($options['tables']) {
    $tables = explode(',', $options['tables']);

    $ini = eZINI::instance('dbschema.ini');
    $schemaPaths = $ini->variable('SchemaSettings', 'SchemaPaths');
    $schemaPaths['postgresql'] = 'vendor/opencontent/ocinstaller/src/Opencontent/Installer/ezpgsqlschema.php';
    $ini->setVariable('SchemaSettings', 'SchemaPaths', $schemaPaths);

    $db = eZDB::instance();
    $dbSchema = eZDbSchema::instance($db);

    $dbSchemaParameters = array(
        'schema' => true,
        'data' => false,
        'format' => 'generic',
        'meta_data' => null,
        'table_type' => null,
        'table_charset' => null,
        'compatible_sql' => true,
        'allow_multi_insert' => null,
        'diff_friendly' => null,
        'table_include' => $tables
    );

    $filename = 'php://stdout';
    $dbSchema->writeArraySchemaFile($filename, $dbSchemaParameters);
}

$script->shutdown();