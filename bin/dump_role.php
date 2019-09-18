<?php

use Symfony\Component\Yaml\Yaml;

require 'autoload.php';

$script = eZScript::instance([
    'description' => "Dump role in yml",
    'use-session' => false,
    'use-modules' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[role:][data_dir:]',
    '',
    array(
        'role' => "Local role name",
        'data_dir' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();

if ($options['role']) {

    $roleSerializer = new \Opencontent\Installer\Dumper\RoleSerializer();
    $roleSerializer->fromRoleName($options['role']);

    $data = $roleSerializer->getData();

    $dataYaml = Yaml::dump($data, 10);

    $trans = eZCharTransform::instance();
    $roleName = $trans->transformByGroup($options['role'], 'urlalias');
    $filename = $roleName . '.yml';

    if ($options['data_dir']) {
        $directory = rtrim($options['data_dir'], '/') . '/roles';

        \eZDir::mkdir($directory, false, true);
        \eZFile::create($filename, $directory, $dataYaml);

        eZCLI::instance()->output($directory . '/' . $filename);

        $output = new ezcConsoleOutput();
        $question = ezcConsoleQuestionDialog::YesNoQuestion($output, "Append to installer.yml", "y");
        if (ezcConsoleDialogViewer::displayDialog($question) == "y") {
            \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data_dir'], [
                'type' => 'role',
                'identifier' => $roleName
            ]);
        }

    } else {
        print_r($dataYaml);
    }
}

$script->shutdown();