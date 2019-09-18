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

        $this->logger->log("Create content " . $this->identifier);

        $contentRepository = new ContentRepository();
        $contentRepository->setEnvironment(EnvironmentLoader::loadPreset('content'));
        $result = $contentRepository->create($content);

        $id = $result['content']['metadata']['id'];
        $nodeId = $result['content']['metadata']['mainNodeId'];
        $this->installerVars['content_' . $this->identifier . '_node'] = $nodeId;
        $this->installerVars['content_' . $this->identifier . '_object'] = $id;

        if ($this->swapWith){
            $this->swap($nodeId);
        }
    }

    private function swap($nodeId)
    {
        $source = $nodeId;
        $target = $this->swapWith;
        eZContentOperationCollection::swapNode($source, $target, array($source, $target));
        $this->installerVars['content_' . $this->identifier . '_node'] = $target;
        if ($this->removeSwapped){
            eZContentOperationCollection::deleteObject(array($nodeId));
        }
    }
}