<?php

require 'autoload.php';

use Symfony\Component\Yaml\Yaml;

$script = eZScript::instance([
    'description' => "Dump content tree in yml",
    'use-session' => false,
    'use-modules' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[url:][id:][data:]',
    '',
    array(
        'url' => "Remote url class definition (https://.../api/opendata/v2/content/browse/...)",
        'id' => "Local content id",
        'data' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();

if ($options['url'] || $options['id']) {

    $dataList = [];
    if ($options['url']) {
        $parts = explode('/api/opendata/v2/content/browse/', $options['url']);
        $remoteHost = array_shift($parts);
        $root = array_pop($parts);

        $client = new \Opencontent\Opendata\Rest\Client\HttpClient($remoteHost);
        $remoteRoot = $client->browse($root, 100);

        $contentTreeNames = $remoteRoot['name'];
        $contentTreeName = current($contentTreeNames);
        $contentTreeName = \Opencontent\Installer\Dumper\Tool::slugize($contentTreeName);

        foreach ($remoteRoot['children'] as $childNode) {
            $cli->output('Download content #' . $childNode['id']);
            $data = $client->read($childNode['id']);
            $dataList[] = $data;
        }

    } elseif ($options['id']) {
        throw new Exception('TODO');
    }

    foreach ($dataList as $dataArray) {
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
            $directory = rtrim($options['data'], '/') . '/contenttrees/' . $contentTreeName;
            eZDir::mkdir($directory, false, true);
            eZFile::create($filename, $directory, $dataYaml);
            $cli->output($directory . '/' . $filename);
        }
    }

    \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data'], [
        'type' => 'contenttree',
        'identifier' => $contentTreeName
    ]);

}

$script->shutdown();