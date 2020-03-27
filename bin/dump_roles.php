<?php

require 'autoload.php';

use Symfony\Component\Yaml\Yaml;

$script = eZScript::instance([
    'description' => "Dump roles in yml",
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

$roles = eZRole::fetchList();

/** @var eZRole $role */
foreach ($roles as $role){

    $roleSerializer = new \Opencontent\Installer\Serializer\RoleSerializer();

    if (!$role instanceof eZRole){
        $cli->error("Role not found");
        $script->shutdown(1);
    }
    $roleName = \Opencontent\Installer\Dumper\Tool::slugize($role->attribute('name'));

    if ($options['data']) {

        $cli->warning($role->attribute('name'));
        if (\Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data'], [
            'type' => 'role',
            'identifier' => $roleName
        ])){
            $identifier = $roleSerializer->serializeToYaml($role, $options['data']);
        }

    } else {
        print_r($roleSerializer->serialize($role));
    }

    foreach ($roleSerializer->getWarnings() as $warning){
        $cli->warning($warning);
    }
}

$script->shutdown();