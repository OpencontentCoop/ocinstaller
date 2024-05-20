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
        'url' => "Remote url or file path classextra definition",
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
    $data = json_decode($json, true);
    $identifier = basename($options['url']);

} elseif ($options['id']) {

    $class = eZContentClass::fetchByIdentifier($options['id']);
    $data = OCClassExtraParametersManager::instance($class)->getAllParameters();
    $identifier = $class->attribute('identifier');
}

if ($data) {
    $dataYaml = Yaml::dump($data, 10);

    if ($options['data']) {

        \Opencontent\Installer\Dumper\Tool::createFile(
            $options['data'],
            'classextra',
            $identifier . '.yml',
            $dataYaml
        );

        if (!$doNotAppend) {
            \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data'], [
                'type' => 'classextra',
                'identifier' => $identifier
            ]);
        }

    } else {
        print_r($dataYaml);
    }
}

$script->shutdown();