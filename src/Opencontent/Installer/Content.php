<?php

namespace Opencontent\Installer;

use Opencontent\Opendata\Api\ContentRepository;
use Opencontent\Opendata\Api\EnvironmentLoader;
use eZContentOperationCollection;

class Content extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $identifier;

    private $swapWith;

    private $removeSwapped;

    private $doUpdate = false;

    public function dryRun()
    {
        $identifier = $this->step['identifier'];
        $needLock = $this->step['lock'] ?? false;
        $lockLog = $needLock ?? ' and lock';
        $this->logger->info("Install{$lockLog} content " . $identifier);
        $this->installerVars['content_' . $identifier . '_node'] = 0;
        $this->installerVars['content_' . $identifier . '_object'] = 0;
        $this->installerVars['content_' . $identifier . '_path_string'] = 0;
    }

    public function install()
    {
        $needLock = $this->step['lock'] ?? false;
        $lockLog = $needLock ?? ' and lock';
        $this->identifier = $this->step['identifier'];
        $this->swapWith = isset($this->step['swap_with']) ? $this->step['swap_with'] : false;
        $this->removeSwapped = isset($this->step['remove_swapped']) && $this->step['remove_swapped'] == true;

        if (isset($this->step['update'])){
            $this->doUpdate = $this->step['update'] == 1;
        }

        $content = $this->ioTools->getJsonContents("contents/{$this->identifier}.yml");

        $sortData = false;
        if (isset($content['sort_data'])){
            $sortData = $content['sort_data'];
            unset($content['sort_data']);
        }

        $contentRepository = new ContentRepository();
        $contentRepository->setEnvironment(EnvironmentLoader::loadPreset('content'));

        $isUpdate = false;
        $alreadyExists = isset($content['metadata']['remoteId']) ? \eZContentObject::fetchByRemoteID($content['metadata']['remoteId']) : false;
        
        if ($alreadyExists){
            $this->logger->info("Update{$lockLog} content " . $this->identifier);
            $content['metadata']['parentNodes'] = [$alreadyExists->mainNode()->attribute('parent_node_id')];
            if ($this->doUpdate) {
                $result = $contentRepository->update($content);
                $nodeId = $result['content']['metadata']['mainNodeId'];
            }else{
                $this->getLogger()->error(' -> already exists');
                $nodeId = $alreadyExists->mainNode()->attribute('node_id');
            }
            $isUpdate = true;
        }else {
            $this->logger->info("Install{$lockLog} content " . $this->identifier);
            $result = $contentRepository->create($content);
            $nodeId = $result['content']['metadata']['mainNodeId'];
        }

        if ($this->swapWith && $isUpdate === false){
            $nodeId = $this->swap($nodeId);
        }

        $node = \eZContentObjectTreeNode::fetch($nodeId);

        if (!$node instanceof \eZContentObjectTreeNode){
            throw new \Exception("Node $nodeId not found");
        }

        if ($sortData && (($this->doUpdate && $isUpdate) || !$isUpdate)){
            $this->setSortAndPriority($node, $sortData);
        }

        $this->rename($node);

        $this->installerVars['content_' . $this->identifier . '_node'] = $node->attribute('node_id');
        $this->installerVars['content_' . $this->identifier . '_object'] = $node->attribute('contentobject_id');
        $this->installerVars['content_' . $this->identifier . '_path_string'] = $node->attribute('path_string');

        $object = \eZContentObject::fetch((int)$result['content']['metadata']['id']);
        if ($object && $needLock){
            $this->lockObject($object);
        }
    }

    private function rename(\eZContentObjectTreeNode $node)
    {
        $object = $node->attribute('object');
        $class = $object->contentClass();
        $object->setName($class->contentObjectName($object));
        $object->store();
    }

    private function swap($nodeId)
    {
        $source = $nodeId;
        $target = $this->swapWith;

        $this->logger->info(" - swap with " . $target);

        eZContentOperationCollection::swapNode($source, $target, array($source, $target));
        if ($this->removeSwapped){
            $this->logger->info(" - remove " . $nodeId);
            eZContentOperationCollection::deleteObject(array($nodeId));
        }

        return $target;
    }

    private function setSortAndPriority(\eZContentObjectTreeNode $node, $data)
    {
        $node->setAttribute('sort_field', $data['sort_field']);
        $node->setAttribute('sort_order', $data['sort_order']);
        $node->setAttribute('priority', $data['priority']);

        $node->store();
    }
}