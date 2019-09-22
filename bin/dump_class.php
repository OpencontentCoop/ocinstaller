<?php

require 'autoload.php';

use Symfony\Component\Yaml\Yaml;

$script = eZScript::instance([
    'description' => "Dump class in yml",
    'use-session' => false,
    'use-modules' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[url:][id:][data_dir:]',
    '',
    array(
        'url' => "Remote url or file path class definition",
        'id' => "Local content class identifier",
        'data_dir' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();

if ($options['url']) {

    $json = file_get_contents($options['url']);

} elseif ($options['id']) {

    $tools = new OCClassTools($options['id']);
    $result = $tools->getLocale();
    $result->attribute('data_map');
    $result->fetchGroupList();
    $result->fetchAllGroups();

    $json = json_encode($result);
}

if ($json) {
    $serializer = new \Opencontent\Installer\Serializer\ContentClassSerializer();

    if ($options['data_dir']) {

        $identifier = $serializer->serializeToYaml($json, $options['data_dir']);

        \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data_dir'], [
            'type' => 'class',
            'identifier' => $identifier
        ]);

    } else {
        print_r($serializer->serialize($json));
    }
}

$script->shutdown();