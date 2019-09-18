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

    public function install()
    {
        $this->identifier = $this->step['identifier'];
        $this->swapWith = isset($this->step['swap_with']) ? $this->step['swap_with'] : false;
        $this->removeSwapped = isset($this->step['remove_swapped']) && $this->step['remove_swapped'] == true;

        $content = $this->ioTools->getJsonContents("contents/{$this->identifier}.yml");

        $this->logger->info("Create content " . $this->identifier);

        $contentRepository = new ContentRepository();
        $contentRepository->setEnvironment(EnvironmentLoader::loadPreset('content'));

        $isUpdate = false;
        if (isset($content['metadata']['remoteId']) && \eZContentObject::fetchByRemoteID($content['metadata']['remoteId'])){
            $result = $contentRepository->update($content);
            $isUpdate = true;
        }else {
            $result = $contentRepository->create($content);
        }

        $nodeId = $result['content']['metadata']['mainNodeId'];
        if ($this->swapWith && $isUpdate === false){
            $nodeId = $this->swap($nodeId);
        }

        $node = \eZContentObjectTreeNode::fetch($nodeId);

        $this->installerVars['content_' . $this->identifier . '_node'] = $node->attribute('node_id');
        $this->installerVars['content_' . $this->identifier . '_object'] = $node->attribute('contentobject_id');
        $this->installerVars['content_' . $this->identifier . '_path_string'] = $node->attribute('path_string');
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
}