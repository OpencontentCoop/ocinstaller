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
$options = $script->getOptions('[url:][id:][data:][do-not-append]',
    '',
    array(
        'url' => "Remote url or file path class definition",
        'id' => "Local content class identifier",
        'data' => "Directory of installer data",
        'do-not-append' => 'do-not-append'
    )
);
$script->initialize();
$cli = eZCLI::instance();
$doNotAppend = $options['do-not-append'];
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

    if ($options['data']) {

        $identifier = $serializer->serializeToYaml($json, $options['data']);

        if (!$doNotAppend) {
            \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data'], [
                'type' => 'class',
                'identifier' => $identifier
            ]);
        }

    } else {
        print_r(Yaml::dump($serializer->serialize($json), 10));
    }
}

$script->shutdown();