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
        'url' => "Remote url or file path classextra definition",
        'id' => "Local content class identifier",
        'data_dir' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();

if ($options['url']) {

    $json = file_get_contents($options['url']);
    $data = json_decode($json, true);
    $identifier = basename($options['url']);

} elseif ($options['id']) {

    $class = eZContentClass::fetchByIdentifier($id);
    $data = OCClassExtraParametersManager::instance($class)->getAllParameters();
    $identifier = $class->attribute('identifier');
}

if ($json) {
    $dataYaml = Yaml::dump($data, 10);

    if ($options['data_dir']) {
        $filename = $identifier . '.yml';
        $directory = rtrim($options['data_dir'], '/') . '/classextra';
        eZDir::mkdir($directory, false, true);
        eZFile::create($filename, $directory, $dataYaml);
        $cli->output($directory . '/' . $filename);

        \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data_dir'], [
            'type' => 'classextra',
            'identifier' => $identifier
        ]);

    } else {
        print_r($data);
    }
}

$script->shutdown();