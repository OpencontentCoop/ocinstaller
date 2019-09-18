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
        'url' => "Remote url or file path class definition",
        'id' => "Local content class id",
        'data_dir' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();

if ($options['url']) {

    $json = file_get_contents($options['url']);

} elseif ($options['id']) {

    $tools = new OCClassTools((int)$options['id']);
    $result = $tools->getLocale();
    $result->attribute('data_map');
    $result->fetchGroupList();
    $result->fetchAllGroups();

    $json = json_encode($result);
}

if ($json) {
    $dumper = \Opencontent\Installer\Dumper\ContentClass::fromJSON($json);
    if ($options['data_dir']) {
        $dumper->store($options['data_dir']);
    } else {
        print_r($dumper->getData());
    }
}

//$installerFactory = new \Opencontent\Installer\StepInstallerFactory(
//    new \Opencontent\Installer\Logger(),
//    new \Opencontent\Installer\InstallerVars(),
//    new \Opencontent\Installer\IOTools($options['data_dir'], new \Opencontent\Installer\InstallerVars()),
//    []
//);
//
//$test = new \Opencontent\Installer\ContentClass(['identifier' => $dumper->getIdentifier()]);
//$installer = $installerFactory->factory($test);
//$installer->install();

$script->shutdown();