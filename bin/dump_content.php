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
        'url' => "Remote url class definition",
        'id' => "Local content id",
        'data_dir' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();

if ($options['url'] || $options['id']) {

    if ($options['url']) {
        $data = file_get_contents($options['url']);
        $dataArray = json_decode($data, true);
    }elseif ($options['id']) {
        $content = \Opencontent\Opendata\Api\Values\Content::createFromEzContentObject(
            eZContentObject::fetch($options['id'])
        );
        $env = new DefaultEnvironmentSettings();
        $dataArray = $env->filterContent($content);
    }

    $contentNames = $dataArray['metadata']['name'];
    $contentName = current($contentNames);

    $trans = eZCharTransform::instance();
    $contentName = $trans->transformByGroup($contentName, 'urlalias');
    $filename = $contentName . '.yml';

    $metadataValues = [
        'remoteId',
        'classIdentifier',
        'sectionIdentifier',
        'stateIdentifiers',
        'languages',
        'parentNodes'
    ];
    $cleanMetadata = [];
    foreach ($dataArray['metadata'] as $key => $value) {
        if (in_array($key, $metadataValues)) {
            $cleanMetadata[$key] = $value;
        }
    }

    $cleanDataArray = [
        'metadata' => $cleanMetadata,
        'data' => $dataArray['data']
    ];

    $dataYaml = Yaml::dump($cleanDataArray, 10);

    if ($options['data_dir']) {
        $directory = rtrim($options['data_dir'], '/') . '/contents';
        eZDir::mkdir($directory, false, true);
        eZFile::create($filename, $directory, $dataYaml);
        $cli->output($directory . '/' . $filename);

        $output = new ezcConsoleOutput();
        $question = ezcConsoleQuestionDialog::YesNoQuestion($output, "Append to installer.yml", "y");
        if (ezcConsoleDialogViewer::displayDialog($question) == "y") {
            \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data_dir'], [
                'type' => 'content',
                'identifier' => $contentName
            ]);
        }

    }

}

$script->shutdown();