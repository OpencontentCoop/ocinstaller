<?php

use Symfony\Component\Yaml\Yaml;

require 'autoload.php';

$script = eZScript::instance([
    'description' => "Dump workflows in yml",
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

$triggers = eZTrigger::fetchList();
print_r($triggers);



$script->shutdown();
exit();

$dataYaml = Yaml::dump($data, 10);

if ($options['data_dir']) {
    $directory = rtrim($options['data_dir'], '/') . '/roles';

    \eZDir::mkdir($directory, false, true);
    \eZFile::create($filename, $directory, $dataYaml);

    eZCLI::instance()->output($directory . '/' . $filename);

} else {
    print_r($dataYaml);
}

$script->shutdown();