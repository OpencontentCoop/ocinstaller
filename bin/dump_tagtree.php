<?php

require 'autoload.php';

use Symfony\Component\Yaml\Yaml;

$script = eZScript::instance([
    'description' => "Dump tagtree in yml",
    'use-session' => false,
    'use-modules' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[url:][id:][data:]',
    '',
    array(
        'url' => "Remote url",
        'id' => "Local tag id",
        'data' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();

/**
 * @param array $remoteTag
 * @param ArrayObject $parentTag
 */
function createTag($remoteTag, $parentTag)
{
    $tag = new ArrayObject([
        'id' => $remoteTag['id'],
        'keyword' => $remoteTag['keyword'],
        'locale' => 'ita-IT',
        'alwaysAvailable' => true,
        'synonyms' => [],
        'keywordTranslations' => $remoteTag['keywordTranslations'],
        'children' => [],
        'hasChildren' => false,
    ]);
    if (isset($remoteTag['synonyms']) && !empty($remoteTag['synonyms'])) {
        foreach ($remoteTag['synonyms'] as $language => $value){
            $tag['synonyms'][$language][] = $value;
        }
    }

    $parentTag['hasChildren'] = true;
    $parentTag['children'][] = $tag;

    return $tag;
}

function recursiveListTag($remoteTag, $parentTag)
{
    $tag = createTag($remoteTag, $parentTag);
    if ($remoteTag['hasChildren']) {
        foreach ($remoteTag['children'] as $remoteTagChild) {
            recursiveListTag($remoteTagChild, $tag);
        }
    }

    return $tag;
}

function getArray($obj)
{
    $array = array(); // noisy $array does not exist
    $arrObj = is_object($obj) ? get_object_vars($obj) : $obj;
    foreach ($arrObj as $key => $val) {
        $val = (is_array($val) || is_object($val)) ? getArray($val) : $val;
        if ($key !== 'id') {
            $array[$key] = $val;
        }
    }
    return $array;
}

function readTree($tagRepository, $name)
{
    $offset = 0;
    $limit = 100;
    $rootTag = $tagRepository->read($name, $offset, $limit)->jsonSerialize();

    if ($rootTag['hasChildren']){
        while ($rootTag['childrenCount'] > count($rootTag['children'])){
            $offset = $offset + $limit;
            $offsetRootTag = $tagRepository->read($name, $offset, $limit)->jsonSerialize();
            $rootTag['children'] = array_merge(
                $rootTag['children'],
                $offsetRootTag['children']
            );
        }

        foreach ($rootTag['children'] as $index => $child){
            if ($child['hasChildren']) {
                $rootTag['children'][$index] = readTree($tagRepository, $child['id']);
            }
        }
    }

    return $rootTag;
}

$parentTag = new ArrayObject([
    'children' => []
]);

if ($options['url']) {

    $remoteUrl = $options['url'];
    $parts = explode('/api/opendata/v2/tags_tree/', $remoteUrl);
    $remoteHost = array_shift($parts);
    $rootTag = array_pop($parts);

    $client = new \Opencontent\Installer\TagClient(
        $remoteHost,
        null,
        null,
        'tags_tree'
    );
    $cli->output('Retrieve tag tree... ', false);
    $remoteRoot = $client->readTree($rootTag);
    $cli->output('done');
    recursiveListTag($remoteRoot, $parentTag);

} elseif ($options['id']) {

    $tagRepository = new \Opencontent\Opendata\Api\TagRepository();
    $remoteRoot = readTree($tagRepository, $options['id']);
    recursiveListTag($remoteRoot, $parentTag);

}

$json = getArray($parentTag)['children'][0];

if ($json) {

    if ($options['data']) {

        $dataYaml = Yaml::dump($json, 10);
        $identifier = \Opencontent\Installer\Dumper\Tool::slugize($remoteRoot['keyword']);
        $filename = $identifier . '.yml';

        \Opencontent\Installer\Dumper\Tool::createFile(
            $options['data'],
            'tagtree',
            $identifier . '.yml',
            $dataYaml
        );

        \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data'], [
            'type' => 'tagtree',
            'identifier' => $identifier
        ]);

    } else {
        print_r(Yaml::dump($json, 10));
    }
}

$script->shutdown();