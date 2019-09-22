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
$options = $script->getOptions('[data_dir:]',
    '',
    array(
        'data_dir' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();
/*
if ($options['data_dir']) {
    $fileList = eZDir::recursiveFind($options['data_dir'] . 'classes', '.yml');
    foreach ($fileList as $file){

        $data = file_get_contents($file);
        $cli->output($file);

        $data = Yaml::parse($data);
        $serializer = new \Opencontent\Installer\Serializer\ContentClassSerializer();
        $json = $serializer->unserialize($data);

        $serializer->setIgnoreDefaultValues(true);
        $data = $serializer->serialize($json);

        file_put_contents($file, Yaml::dump($data, 10));
        $serializer->setIgnoreDefaultValues(false);
    }
}
*/
$script->shutdown();