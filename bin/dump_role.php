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
$options = $script->getOptions('[role:][data:]',
    '',
    array(
        'role' => "Local role name",
        'data' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();

if ($options['role']) {

    $roleSerializer = new \Opencontent\Installer\Serializer\RoleSerializer();

    /** @var eZRole $role */
    $role = eZRole::fetchByName($options['role']);
    if (!$role instanceof eZRole){
        $cli->error("Role not found");
        $script->shutdown(1);
    }
    $roleName = \Opencontent\Installer\Dumper\Tool::slugize($role->attribute('name'));

    if ($options['data']) {

        $identifier = $roleSerializer->serializeToYaml($role, $options['data']);

        \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data'], [
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