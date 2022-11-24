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
        $object = eZContentObject::fetch($options['id']);
        if (!$object instanceof eZContentObject){
            throw new Exception("Object not found");
        }
        $content = \Opencontent\Opendata\Api\Values\Content::createFromEzContentObject(
            $object
        );
        $env = new DefaultEnvironmentSettings();
        $dataArray = $env->filterContent($content);
        $node = $object->mainNode();
        $dataArray['sort_data'] = [
            'sort_field' => (int)$node->attribute('sort_field'),
            'sort_order' => (int)$node->attribute('sort_order'),
            'priority' => (int)$node->attribute('priority'),
        ];
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
        'data' => $dataArray['data'],
        'sort_data' => $dataArray['sort_data'],
    ];

    $dataYaml = Yaml::dump($cleanDataArray, 10);

    if ($options['data']) {

        \Opencontent\Installer\Dumper\Tool::createFile(
            $options['data'],
            'contents',
            $filename,
            $dataYaml
        );

        \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data'], [
            'type' => 'content',
            'identifier' => $contentName
        ]);

    }else{
        echo $dataYaml;
    }

}

$script->shutdown();