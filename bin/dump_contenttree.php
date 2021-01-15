<?php

require 'autoload.php';

use Symfony\Component\Yaml\Yaml;
use Opencontent\Opendata\Api\ContentRepository;
use Opencontent\Opendata\Api\ContentBrowser;

$script = eZScript::instance([
    'description' => "Dump content tree in yml",
    'use-session' => false,
    'use-modules' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[url:][id:][data:][prefix:][recursion:][classes:][append]',
    '',
    array(
        'url' => "Remote url class definition (https://.../api/opendata/v2/content/browse/...)",
        'id' => "Local content id",
        'data' => "Directory of installer data",
        'prefix' => "Identifier prefix",
        'recursion' => "Recursion",
        'classes' => "Classes comma separated",
        'append' => "Append to installer.yml",
    )
);
$script->initialize();
$cli = eZCLI::instance();

$user = eZUser::fetchByName( 'admin' );
eZUser::setCurrentlyLoggedInUser( $user , $user->attribute( 'contentobject_id' ) );

function dumpContent($dataArray, $dataDir, $contentTreeName)
{
    $contentNames = $dataArray['metadata']['name'];
    $contentName = current($contentNames);
    $contentName = \Opencontent\Installer\Dumper\Tool::slugize($contentName);
    $filename = $contentName . '.yml';

    $metadataValues = [
        'remoteId',
        'classIdentifier',
        'sectionIdentifier',
        'languages',
    ];
    $cleanMetadata = [];
    foreach ($dataArray['metadata'] as $key => $value) {
        if (in_array($key, $metadataValues)) {
            $cleanMetadata[$key] = $value;
        }
    }

    if ($dataArray['data']['ita-IT']['decorrenza_di_pubblicazione'][0] == '') unset($dataArray['data']['ita-IT']['decorrenza_di_pubblicazione']);
    if ($dataArray['data']['ita-IT']['aggiornamento'][0] == '') unset($dataArray['data']['ita-IT']['aggiornamento']);
    if ($dataArray['data']['ita-IT']['termine_pubblicazione'][0] == '') unset($dataArray['data']['ita-IT']['termine_pubblicazione']);
    if (empty($dataArray['data']['ita-IT']['fields_blocks'])) unset($dataArray['data']['ita-IT']['fields_blocks']);

    $cleanDataArray = [
        'metadata' => $cleanMetadata,
        'data' => $dataArray['data'],
        'sort_data' => $dataArray['sort_data'],
    ];

    $dataYaml = Yaml::dump($cleanDataArray, 10);

    if ($dataDir) {
        \Opencontent\Installer\Dumper\Tool::createFile(
            $dataDir,
            'contenttrees/' . $contentTreeName,
            $filename,
            $dataYaml
        );
    }
}

$avoidDuplications = [];

function dumpTree($remoteRoot, $contentClient, $browser, $dataDir, $prefix, $maxRecursion = 3, $classes = false, $append = false, $recursion = 0, $parentTreeName = '')
{
    global $avoidDuplications;

    $remoteRoot = json_decode(json_encode($remoteRoot), true);
    $dataList = [];
    $contentTreeNames = $remoteRoot['name'];
    $contentTreeName = current($contentTreeNames);
    $currentTreeName = \Opencontent\Installer\Dumper\Tool::slugize($contentTreeName);
    $contentTreeName = $parentTreeName . \Opencontent\Installer\Dumper\Tool::slugizeAndCompress($contentTreeName);
    if (isset($avoidDuplications[$contentTreeName])){
        $avoidDuplications[$contentTreeName] += 1;
        $contentTreeName = $contentTreeName . $avoidDuplications[$contentTreeName];
    }else{
        $avoidDuplications[$contentTreeName] = 1;
    }
    eZCLI::instance()->output("[$recursion] Fetch $contentTreeName");

    foreach ($remoteRoot['children'] as $childNode) {
        $childNode = (array)$childNode;
        if ((is_array($classes) && in_array($childNode['classIdentifier'], $classes)) || $classes === false) {
            $data = json_decode(json_encode($contentClient->read($childNode['id'])), true);
            $data['sort_data'] = [
                'sort_field' => (int)$childNode['sortField'],
                'sort_order' => (int)$childNode['sortOrder'],
                'priority' => (int)$childNode['priority'],
            ];
            dumpContent($data, $dataDir, $prefix . $contentTreeName);
        }
    }

    eZCLI::instance()->warning($prefix . $contentTreeName);
    if ($dataDir) {
        \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($dataDir, [
            'type' => 'contenttree',
            'identifier' => $prefix . $contentTreeName,
            'parent' => '$contenttree_' . $prefix . rtrim($parentTreeName, '-'). '_' . $currentTreeName . '_node',
        ], $append);
    }

    if ($recursion < $maxRecursion) {
        foreach ($remoteRoot['children'] as $childNode) {
            $childNode = (array)$childNode;
            if ((is_array($classes) && in_array($childNode['classIdentifier'], $classes)) || $classes === false) {
                $childRemoteNode = $browser->browse($childNode['nodeId']);
                $recursion++;
                dumpTree($childRemoteNode, $contentClient, $browser, $dataDir, $prefix, $maxRecursion, $classes, $append, $recursion, $contentTreeName.'-');
                $recursion--;
            }
        }
    }
}

if ($options['url'] || $options['id']) {

    $contentClient = false;
    if ($options['url']) {
        $parts = explode('/api/opendata/v2/content/browse/', $options['url']);
        $remoteHost = array_shift($parts);
        $root = array_pop($parts);

        $contentClient = $browser = new \Opencontent\Opendata\Rest\Client\HttpClient($remoteHost);
        $remoteRoot = $contentClient->browse($root, 100);

    } elseif ($options['id']) {

        $contentClient = new ContentRepository();
        $contentClient->setEnvironment(new DefaultEnvironmentSettings());
        $browser = new ContentBrowser();
        $browser->setEnvironment(new DefaultEnvironmentSettings());

        $remoteRoot = (array)$browser->browse($options['id'], 0, 100);
    }

    $classes = $options['classes'] ? explode(',', $options['classes']) : false;
    $maxRecursion = $options['recursion'] ? (int)$options['recursion'] : 1;
    if ($remoteRoot){
        dumpTree($remoteRoot, $contentClient, $browser, $options['data'], $options['prefix'], $maxRecursion, $classes, $options['append']);
    }

}

$script->shutdown();