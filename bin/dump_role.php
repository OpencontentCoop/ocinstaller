<?php

require 'autoload.php';

use Symfony\Component\Yaml\Yaml;

$script = eZScript::instance([
    'description' => "Dump role in yml",
    'use-session' => false,
    'use-modules' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[role:][data_dir:]',
    '',
    array(
        'role' => "Local role name",
        'data_dir' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();

if ($options['role']) {

    $roleSerializer = new \Opencontent\Installer\Serializer\RoleSerializer();

    /** @var eZRole $role */
    $role = eZRole::fetchByName($options['role']);

    if ($options['data_dir']) {

        $identifier = $roleSerializer->serializeToYaml($role, $options['data_dir']);

        \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data_dir'], [
            'type' => 'role',
            'identifier' => $roleName
        ]);

    } else {
        print_r($roleSerializer->serialize($role));
    }

    foreach ($roleSerializer->getWarnings() as $warning){
        $cli->warning($warning);
    }
}

$script->shutdown();