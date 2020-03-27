<?php

require 'autoload.php';

use Symfony\Component\Yaml\Yaml;

$script = eZScript::instance([
    'description' => "Dump local classes in yml",
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

$classes = eZContentClass::fetchList();

foreach ($classes as $class){
    $identifier = $class->attribute('identifier');
    $tools = new OCClassTools($class->attribute('id'));
    $result = $tools->getLocale();
    $result->attribute('data_map');
    $result->fetchGroupList();
    $result->fetchAllGroups();
    $json = json_encode($result);
    $serializer = new \Opencontent\Installer\Serializer\ContentClassSerializer();

    if ($options['data']) {

        $cli->warning($identifier);
        if (\Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data'], [
            'type' => 'class',
            'identifier' => $identifier
        ])) {
            $identifier = $serializer->serializeToYaml($json, $options['data']);

            $extraData = OCClassExtraParametersManager::instance($class)->getAllParameters();
            $dataYaml = Yaml::dump($extraData, 10);
            \Opencontent\Installer\Dumper\Tool::createFile(
                $options['data'],
                'classextra',
                $identifier . '.yml',
                $dataYaml
            );
            \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data'], [
                'type' => 'classextra',
                'identifier' => $identifier
            ], true);

        }

    } else {
        print_r(Yaml::dump($serializer->serialize($json), 10));
    }
}

$script->shutdown();