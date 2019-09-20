<?php

require 'autoload.php';

use Symfony\Component\Yaml\Yaml;

$script = eZScript::instance([
    'description' => "Dump class in yml",
    'use-session' => false,
    'use-modules' => false,
    'use-extensions' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[data_dir:]',
    '',
    array(
        'data_dir' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();

if ($options['data_dir']) {
    $fileList = eZDir::recursiveFind($options['data_dir'] . '/classes', '.yml');
    foreach ($fileList as $file){
        $data = file_get_contents($file);
        $json = Yaml::parse($data);
        $hydrate = \Opencontent\Installer\Dumper\ContentClass::hydrateData($json);
        if ($hydrate['Identifier'] == 'event') {
            print_r($hydrate);
            die();
        }
    }
}

$script->shutdown();