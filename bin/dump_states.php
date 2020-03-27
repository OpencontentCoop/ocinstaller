<?php

require 'autoload.php';

use Symfony\Component\Yaml\Yaml;

$script = eZScript::instance([
    'description' => "Dump local states in yml",
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

$groupCount = eZPersistentObject::count(eZContentObjectStateGroup::definition());
/** @var eZContentObjectStateGroup[] $groups */
$groups = eZContentObjectStateGroup::fetchByOffset($groupCount, 0);


foreach ($groups as $group) {

    $name = [];
    foreach ($group->translations() as $language) {
        $name[$language->attribute('language')->attribute('locale')] = $language->attribute('name');
    }

    $data = [
        'group_name' => $name,
        'group_identifier' => $group->attribute('identifier'),
        'states' => [],
    ];

    /** @var eZContentObjectState $state */
    foreach ($group->states() as $state) {
        $name = [];
        foreach ($state->translations() as $language) {
            $name[$language->attribute('language')->attribute('locale')] = $language->attribute('name');
        }
        $data['states'][] = [
            'identifier' => $state->attribute('identifier'),
            'name' => $name,
        ];
    }

    $dataYaml = Yaml::dump($data, 10);
    if ($options['data']) {

        $cli->warning($group->attribute('identifier'));
        if (\Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data'], [
            'type' => 'state',
            'identifier' => $group->attribute('identifier')
        ])) {
            \Opencontent\Installer\Dumper\Tool::createFile(
                $options['data'],
                'states',
                $group->attribute('identifier') . '.yml',
                $dataYaml
            );
        }

    } else {
        print_r($dataYaml);
        echo "\n";
    }
}


$script->shutdown();