#!/usr/bin/env php
<?php

require 'autoload.php';
if (!class_exists('OpenContentInstaller')) require __DIR__ . '/installer.php';
if (!class_exists('OpenContentTagTreeInstaller')) require __DIR__ . '/tagtree.php';

$cleanDataDirectoryDefault = 'openpa_tools/installer/cleandata';

$script = eZScript::instance([
    'description' => "Installer",
    'use-session' => false,
    'use-modules' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[data_dir:][languages:][cleandata_dir:][cleanup][only-base][only-data]',
    '',
    array(
        'data_dir' => "Directory of installer data",
        'cleanup' => "Cleanup db before install",
        'only-base' => "Install only base without extension schema",
        'only-data' => "Install only data without base and extension schema",
        'languages' => "Comma separates language code list, first is primary (default is: eng-GB,ita-IT,ger-DE)",
        'cleandata_dir' => "Directory of clean db_schema.dba and db_data.dba files (default is: $cleanDataDirectoryDefault)",
    )
);
$script->initialize();
$cli = eZCLI::instance();
$output = new ezcConsoleOutput();

eZDB::setErrorHandling(eZDB::ERROR_HANDLING_EXCEPTIONS);

/** @var eZDBInterface $db */
$db = eZDB::instance();

try {

    // parameters
    $cleanDb = $options['cleanup'];
    $installBaseSchema = !$options['only-data'];
    $installExtensionsSchema = !$options['only-base'];

    $cleanDataDirectory = $options['cleandata_dir'] ? rtrim($options['cleandata_dir']) : $cleanDataDirectoryDefault;
    $baseSchema = $cleanDataDirectory . '/db_schema.dba';
    $baseData = $cleanDataDirectory . '/db_data.dba'; //admin change_password

    $languages = $options['languages'] ? $options['languages'] : 'eng-GB,ita-IT,ger-DE';
    $languageList = explode(',', $languages);
    $primaryLanguageCode = array_shift($languageList);
    $extraLanguageCodes = $languageList;

    $activeExtensions = ['ezmbpaex'];

    $dataDir = $options['data_dir'];
    if ($dataDir && !is_dir($dataDir)){
        throw new Exception("Directory $dataDir not found");
    }

    // install
    $installer = new OpenContentInstaller($db, $dataDir);

    if ($cleanDb) {
        $installer->cleanup();
    }

    if ($installBaseSchema) {
        $installer->installSchemaAndData($baseSchema, $baseData);

        if ($installExtensionsSchema) {
            $activeExtensions = array_merge(
                $activeExtensions,
                eZExtension::activeExtensions()
            );
        }
        $installer->installExtensionsSchema($activeExtensions);

        $installer->setLanguages($primaryLanguageCode, $extraLanguageCodes);
        $installer->expiryPassword();
    }

    $installer->install();


} catch (Throwable $e) {
    $db->rollback();
    $cli->error($e->getMessage() . ' ' . $e->getFile() . '#' . $e->getLine());
    print $e->getTraceAsString();
}

$script->shutdown();

// php bin/php/ezsqldumpschema.php --type=postgresql --user=openpa --host=db.consorzio-astratto --password= --output-array --output-types=data ez20150103_openpa_rivadelgarda openpa_tools/installer/cleandata/db_data.dba