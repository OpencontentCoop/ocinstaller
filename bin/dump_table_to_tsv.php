<?php

require 'autoload.php';

$script = eZScript::instance([
    'description' => "Dump table in tsv",
    'use-session' => false,
    'use-modules' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[table:][data:]',
    '',
    array(
        'table' => "Table name",
        'data' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();

if ($options['table']) {
    $db = eZDB::instance();
    $rows = pg_copy_to(eZDB::instance()->DBConnection, $options['table']);
    $tsv = implode("", $rows);

    if ($options['data']) {

        $filename = $options['table'] . '.tsv';

        \Opencontent\Installer\Dumper\Tool::createFile(
            $options['data'],
            'sql',
            $filename,
            $tsv
        );

        \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data'], [
            'type' => 'sql_copy_from_tsv',
            'identifier' => $options['table'],
            'table' => $options['table'],
        ]);

    } else {
        print_r($tsv);
    }

}

$script->shutdown();

//php vendor/opencontent/ocinstaller/bin/dump_table_to_tsv.php -sopencitybuglianoqa_backend --table=eztags --data=vendor/opencity-labs/opencity-installer
//php vendor/opencontent/ocinstaller/bin/dump_table_to_tsv.php -sopencitybuglianoqa_backend --table=eztags_keyword --data=vendor/opencity-labs/opencity-installer