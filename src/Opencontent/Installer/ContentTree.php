<?php

namespace Opencontent\Installer;

use Opencontent\Opendata\Api\ContentRepository;
use Opencontent\Opendata\Api\EnvironmentLoader;
use Opencontent\Opendata\Rest\Client\HttpClient;
use Opencontent\Opendata\Rest\Client\PayloadBuilder;

class ContentTree extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $identifier;

    private $doUpdate = false;

    private $doRemoveLocations = false;

    public function dryRun()
    {
        $this->identifier = $this->step['identifier'];
        $this->logger->info("Install contenttree " . $this->identifier);
        if (is_dir($this->ioTools->getDataDir() . "/contenttrees/{$this->identifier}")) {
            $files = \eZDir::findSubitems($this->ioTools->getDataDir() . "/contenttrees/{$this->identifier}", 'f');
            foreach ($files as $file) {
                if (\eZFile::suffix($file) == 'yml') {
                    $identifier = substr(basename($file), 0, -4);
                    $contents[$identifier] = "/contenttrees/{$this->identifier}/$file";
                }
            }
        }
        foreach ($contents as $identifier => $content){
            $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier .  '_node'] = 0;
            $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier . '_object'] = 0;
            $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier . '_path_string'] = 0;
        }
    }

    public function install()
    {
        $this->identifier = $this->step['identifier'];

        if (isset($this->step['update'])){
            $this->doUpdate = $this->step['update'] == 1;
        }

        if (isset($this->step['remove_locations'])){
            $this->doRemoveLocations = $this->step['remove_locations'] == 1;
        }

        $source = isset($this->step['source']) ? $this->step['source'] : '';
        if (!isset($this->step['parent'])){
            throw new \Exception("Missing parent param");
        }
        $parentNodeId = $this->step['parent'];
        if (strpos($source, 'http') !== false) {
            $this->installFromRemote($source, $parentNodeId);
        } elseif (is_dir($this->ioTools->getDataDir() . "/contenttrees/{$this->identifier}")) {
            $this->installFromLocal($parentNodeId);
        }
    }

    private function installFromLocal($parentNodeId)
    {
        $this->logger->info("Install contenttree " . $this->identifier . " from " . "contenttrees/{$this->identifier}");

        $contents = [];
        $files = \eZDir::findSubitems($this->ioTools->getDataDir() . "/contenttrees/{$this->identifier}", 'f');
        foreach ($files as $file) {
            if (\eZFile::suffix($file) == 'yml') {
                $identifier = substr(basename($file), 0, -4);
                $contents[$identifier] = $this->ioTools->getJsonContents("/contenttrees/{$this->identifier}/$file");
            }
        }

        $contentRepository = new ContentRepository();
        $contentRepository->setEnvironment(EnvironmentLoader::loadPreset('content'));

        foreach ($contents as $identifier => $content){
            $this->logger->info(" - " . $content['metadata']['remoteId']);

            $sortData = false;
            if (isset($content['sort_data'])){
                $sortData = $content['sort_data'];
                unset($content['sort_data']);
            }

            $client = new HttpClient('');
            $payload = $client->getPayload($content);
            $payload->setParentNodes([$parentNodeId]);
            $payload->unSetData('image');
            $payload->unSetData('managed_by_area');
            $payload->unSetData('managed_by_political_body');
            $payload->unSetData('help');
            unset($payload['metadata']['assignedNodes']);
            unset($payload['metadata']['classDefinition']);

            $isUpdate = false;
            $alreadyExists = isset($content['metadata']['remoteId']) ? \eZContentObject::fetchByRemoteID($payload['metadata']['remoteId']) : false;
            if ($alreadyExists){

                if ($this->doUpdate) {

                    $removeNodeAssignments = [];
                    if ($this->doRemoveLocations) {
                        foreach ($alreadyExists->assignedNodes() as $node) {
                            if (!in_array($node->attribute('parent_node_id'), $payload->getMetadaData('parentNodes'))) {
                                $removeNodeAssignments[$node->attribute('node_id')] = $node->fetchParent()->attribute('name');
                            }
                        }
                    }

                    $result = $contentRepository->update($payload->getArrayCopy());
                    $nodeId = $result['content']['metadata']['mainNodeId'];
                    if (count($removeNodeAssignments) > 0){
                        $this->getLogger()->debug(' -> remove locations in ' . implode(', ', array_values($removeNodeAssignments)));
                        \eZContentOperationCollection::removeNodes(array_keys($removeNodeAssignments));
                        $nodeId = \eZContentObject::fetchByRemoteID($payload['metadata']['remoteId'])->mainNodeID();
                    }
                    
                }else{
                    $this->getLogger()->error(' -> already exists');
                    $nodeId = $alreadyExists->mainNode()->attribute('node_id');
                }
                $isUpdate = true;
            }else {
                $result = $contentRepository->create($payload->getArrayCopy());
                $nodeId = $result['content']['metadata']['mainNodeId'];
            }

            $node = \eZContentObjectTreeNode::fetch($nodeId);
            if (!$node instanceof \eZContentObjectTreeNode){
                throw new \Exception("Node $nodeId not found");
            }

            if ($sortData && (($this->doUpdate && $isUpdate) || !$isUpdate)){
                $this->setSortAndPriority($node, $sortData);
            }

            $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier .  '_node'] = $node->attribute('node_id');
            $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier . '_object'] = $node->attribute('contentobject_id');
            $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier . '_path_string'] = $node->attribute('path_string');
        }
    }

    private function installFromRemote($remoteUrl, $parentNodeId)
    {
        $parts = explode('/api/opendata/v2/content/browse/', $remoteUrl);
        $remoteHost = array_shift($parts);
        $root = array_pop($parts);

        $contentRepository = new ContentRepository();
        $contentRepository->setEnvironment(EnvironmentLoader::loadPreset('content'));

        $this->logger->info("Install contenttree " . $this->identifier . " from $remoteHost");

        $client = new HttpClient($remoteHost);
        $remoteRoot = $client->browse($root, 100);
        foreach ($remoteRoot['children'] as $childNode) {
            try {
                $child = $client->read($childNode['id']);

                $contentNames = $child['metadata']['name'];
                $contentName = current($contentNames);
                $identifier = \Opencontent\Installer\Dumper\Tool::slugize($contentName);
                $this->logger->info(" - $contentName");

                $result = $client->import($child, $contentRepository, function (PayloadBuilder $payload) use ($parentNodeId) {
                    $payload->setParentNodes([$parentNodeId]);
                    $payload->unSetData('image');
                    $payload->unSetData('managed_by_area');
                    $payload->unSetData('managed_by_political_body');
                    $payload->unSetData('help');
                    unset($payload['metadata']['assignedNodes']);
                    unset($payload['metadata']['classDefinition']);

                    return $payload;
                });

                $nodeId = $result['content']['metadata']['mainNodeId'];
                $node = \eZContentObjectTreeNode::fetch($nodeId);

                $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier .  '_node'] = $node->attribute('node_id');
                $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier . '_object'] = $node->attribute('contentobject_id');
                $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier . '_path_string'] = $node->attribute('path_string');

            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
            }
        }
    }

    private function setSortAndPriority(\eZContentObjectTreeNode $node, $data)
    {
        $node->setAttribute('sort_field', $data['sort_field']);
        $node->setAttribute('sort_order', $data['sort_order']);
        $node->setAttribute('priority', $data['priority']);

        $node->store();
    }
}