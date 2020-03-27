<?php

require 'autoload.php';

use Symfony\Component\Yaml\Yaml;

$script = eZScript::instance([
    'description' => "Dump local sections in yml",
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

$sections = eZSection::fetchList();

foreach ($sections as $section) {

    $data = [
        'name' => $section->attribute('name'),
        'identifier' => $section->attribute('identifier'),
        'navigation_part' => $section->attribute('navigation_part_identifier'),
    ];

    $dataYaml = Yaml::dump($data, 10);
    if ($options['data']) {

        $cli->warning($section->attribute('identifier'));
        if (\Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data'], [
            'type' => 'section',
            'identifier' => $section->attribute('identifier')
        ])) {
            \Opencontent\Installer\Dumper\Tool::createFile(
                $options['data'],
                'sections',
                $section->attribute('identifier') . '.yml',
                $dataYaml
            );
        }

    } else {
        print_r($dataYaml);
        echo "\n";
    }
}


$script->shutdown();