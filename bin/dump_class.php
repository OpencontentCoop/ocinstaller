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
$options = $script->getOptions('[url:][data_dir:]',
    '',
    array(
        'url' => "Remote url class definition",
        'data_dir' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();

if ($options['url']) {

    $data = file_get_contents($options['url']);
    $dataArray = json_decode($data, true);

    $filename = $dataArray['Identifier'] . '.yml';
    $dataYaml = Yaml::dump($dataArray, 10);

    if ($options['data_dir']){
        $directory = rtrim($options['data_dir'], '/') . '/classes';
        eZDir::mkdir($directory, false, true);
        eZFile::create($filename, $directory, $dataYaml);
        $cli->output($directory . '/' . $filename);
    }

}

$script->shutdown();