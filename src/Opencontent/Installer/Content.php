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

    public function __construct($step)
    {
        $this->identifier = $step['identifier'];
        $this->swapWith = isset($step['swap_with']) ? $step['swap_with'] : false;
        $this->removeSwapped = isset($step['remove_swapped']) && $step['remove_swapped'] == true;
    }

    public function install()
    {
        $content = $this->ioTools->getJsonContents("contents/{$this->identifier}.yml");

        $this->logger->info("Create content " . $this->identifier);

        $contentRepository = new ContentRepository();
        $contentRepository->setEnvironment(EnvironmentLoader::loadPreset('content'));
        $result = $contentRepository->create($content);

        $nodeId = $result['content']['metadata']['mainNodeId'];
        if ($this->swapWith){
            $nodeId = $this->swap($nodeId);
        }

        $node = \eZContentObjectTreeNode::fetch($nodeId);

        $this->installerVars['content_' . $this->identifier . '_node'] = $node->attribute('node_id');
        $this->installerVars['content_' . $this->identifier . '_object'] = $node->attribute('contentobject_id');
        $this->installerVars['content_' . $this->identifier . '_path_string'] = $node->attribute('path_string');


        if ($this->swapWith){
            $this->swap($node->attribute('node_id'));
        }
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