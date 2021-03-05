<?php

namespace Opencontent\Installer;

use eZContentObject;
use Opencontent\Opendata\Api\ContentRepository;
use Opencontent\Opendata\Api\ContentSearch;
use Opencontent\Opendata\Api\EnvironmentLoader;
use Opencontent\Opendata\Api\Values\SearchResults;

class DeprecateTopic extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private static $topicTreeObjectList = [];

    public function dryRun()
    {
        $identifier = $this->step['identifier'];
        $source = eZContentObject::fetchByRemoteID($identifier);
        if (!$source instanceof eZContentObject){
            throw new \Exception("Topic $identifier not found");
        }
        $sourceName = $source->attribute('name');

        if (isset($this->step['target'])) {
            $target = $this->step['target'];
            $targetTopic = eZContentObject::fetchByRemoteID($target);
            if ($targetTopic instanceof eZContentObject) {
                $targetName = $targetTopic->attribute('name');
            } else {
                $targetName = $target . ' (not yet installed)';
            }
            $searchRepository = new ContentSearch();
            $searchRepository->setEnvironment(EnvironmentLoader::loadPreset('content'));
            $search = $searchRepository->search('topics.id = ' . $source->attribute('id') . ' limit 1', []);
            $this->logger->info("Remap $search->totalCount objects from from $identifier ($sourceName) to $target ($targetName) and move topic in node #$moveIn");
        }

        if (isset($this->step['move_in'])) {
            $moveIn = $this->step['move_in'];
            $this->logger->info("Move topic $identifier ($sourceName) in node #$moveIn");
        }
    }

    public function install()
    {
        $identifier = $this->step['identifier'];
        $sourceTopic = eZContentObject::fetchByRemoteID($identifier);
        if (!$sourceTopic instanceof eZContentObject){
            throw new \Exception("Topic $identifier not found");
        }
        $sourceName = $sourceTopic->attribute('name');

        if (isset($this->step['target'])) {
            $target = $this->step['target'];
            $targetTopic = eZContentObject::fetchByRemoteID($target);
            if (!$targetTopic instanceof eZContentObject) {
                throw new \Exception("Target topic $target not found");
            }
            $targetName = $targetTopic->attribute('name');
            $searchRepository = new ContentSearch();
            $searchRepository->setEnvironment(EnvironmentLoader::loadPreset('content'));
            $searchResults = $searchRepository->search('topics.id = ' . $sourceTopic->attribute('id'));

            if ($searchResults->totalCount > 0) {
                $this->logger->info("Remap $searchResults->totalCount objects from $identifier ($sourceName) to $target ($targetName)");
                $this->remapTopic($sourceTopic, $targetTopic, $searchResults);
            }
        }

        if (isset($this->step['move_in'])) {
            $moveIn = $this->step['move_in'];
            $this->logger->info("Move topic $identifier ($sourceName) in node #$moveIn");
            \eZContentObjectTreeNodeOperations::move($sourceTopic->attribute('main_node_id'), $moveIn);
        }
    }

    private function remapTopic(eZContentObject $source, eZContentObject $target, SearchResults $searchResults)
    {
        foreach ($searchResults->searchHits as $hit){
            $object = eZContentObject::fetch($hit['metadata']['id']);
            $dataMap = $object->dataMap();
            if (isset($dataMap['topics'])){
                $topicList = explode('-', $dataMap['topics']->toString());
                if (in_array($source->attribute('id'), $topicList)) {
//                    $remapTopicList = [];
//                    foreach ($topicList as $item){
//                        if ($item == $source->attribute('id')){
//                            $item = $target->attribute('id');
//                        }
//                        $remapTopicList[] = $item;
//                    }
//                    $topicList = $remapTopicList;
                    foreach ($this->getTopicTreeObjectList($target) as $id) {
                        $topicList[] = $id;
                    }
                    $topicList = array_unique($topicList);

                    \eZContentFunctions::updateAndPublishObject($object, ['attributes' => [
                        'topics' => implode('-', $topicList)
                    ]]);
                    $this->logger->debug(" - #" . $object->attribute('id') . ' ' . $object->attribute('name'));
                }
            }
            eZContentObject::clearCache();
        }
        if ($searchResults->nextPageQuery){
            $nextQuery = explode('search/', $searchResults->nextPageQuery)[1];
            $search = $searchRepository->search($nextQuery);
            $this->remapTopic($source, $target, $search);
        }
    }

    private function getTopicTreeObjectList(eZContentObject $topic)
    {
        if (!isset(self::$topicTreeObjectList[$topic->attribute('id')])){
            self::$topicTreeObjectList[$topic->attribute('id')] = [];
            /** @var eZContentObjectTreeNode[] $path */
            $path = $topic->mainNode()->fetchPath();
            foreach ($path as $item){
                if ($item->attribute('class_identifier') == 'topic'){
                    self::$topicTreeObjectList[$topic->attribute('id')][] = $item->attribute('contentobject_id');
                }
            }
            self::$topicTreeObjectList[$topic->attribute('id')][] = $topic->attribute('id');
        }

        return self::$topicTreeObjectList[$topic->attribute('id')];
    }
}