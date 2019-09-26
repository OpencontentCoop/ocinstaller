<?php

require 'autoload.php';

use Symfony\Component\Yaml\Yaml;

$script = eZScript::instance([
    'description' => "Dump content in yml",
    'use-session' => false,
    'use-modules' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[url:][id:][data:]',
    '',
    array(
        'url' => "Remote url class definition",
        'id' => "Local content id",
        'data' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();

if ($options['url'] || $options['id']) {

    if ($options['url']) {
        $data = file_get_contents($options['url']);
        $dataArray = json_decode($data, true);
    } elseif ($options['id']) {
        $content = \Opencontent\Opendata\Api\Values\Content::createFromEzContentObject(
            eZContentObject::fetch($options['id'])
        );
        $env = new DefaultEnvironmentSettings();
        $dataArray = $env->filterContent($content);
    }

    $contentNames = $dataArray['metadata']['name'];
    $contentName = current($contentNames);
    $contentName = \Opencontent\Installer\Dumper\Tool::slugize($contentName);
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

    if ($options['data']) {
        $directory = rtrim($options['data'], '/') . '/contents';
        eZDir::mkdir($directory, false, true);
        eZFile::create($filename, $directory, $dataYaml);
        $cli->output($directory . '/' . $filename);

        \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data'], [
            'type' => 'content',
            'identifier' => $contentName
        ]);

    }

}

$script->shutdown();