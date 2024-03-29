<?php

namespace Opencontent\Installer;

use eZContentObject;
use Opencontent\Opendata\Api\ContentRepository;
use Opencontent\Opendata\Api\EnvironmentLoader;
use Opencontent\Opendata\Rest\Client\HttpClient;
use Opencontent\Opendata\Rest\Client\PayloadBuilder;
use Symfony\Component\Yaml\Yaml;

class ContentTree extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $identifier;

    private $doUpdate = false;

    private $doRemoveLocations = false;

    public function dryRun()
    {
        $this->identifier = $this->step['identifier'];
        $contents = [];
        $needLock = $this->step['lock'] ?? false;
        $lockLog = $needLock ?? ' and lock';
        $this->logger->info("Install{$lockLog} contenttree " . $this->identifier);
        if (is_dir($this->ioTools->getDataDir() . "/contenttrees/{$this->identifier}")) {
            $files = \eZDir::findSubitems($this->ioTools->getDataDir() . "/contenttrees/{$this->identifier}", 'f');
            foreach ($files as $file) {
                if (\eZFile::suffix($file) == 'yml') {
                    $identifier = substr(basename($file), 0, -4);
                    //$contents[$identifier] = "/contenttrees/{$this->identifier}/$file";
                    $contents[$identifier] = $this->ioTools->getJsonContents("/contenttrees/{$this->identifier}/$file");
                    $alreadyExists = isset($contents[$identifier]['metadata']['remoteId']) ? \eZContentObject::fetchByRemoteID($contents[$identifier]['metadata']['remoteId']) : false;
                    if ($alreadyExists) {
                        $this->logger->info(" - Update content " . $identifier);

                    }else{
                        $this->logger->warning(" - Create content " . $identifier);
                        sleep(1);
                    }
                }
            }
        }
        foreach ($contents as $identifier => $content) {
            $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier . '_node'] = 0;
            $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier . '_object'] = 0;
            $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier . '_path_string'] = 0;
        }
        $resetFields = $this->step['reset'] ?? [];
        if (count($resetFields)) {
            $this->logger->info(" - reset " . implode(', ', $resetFields));
        }
    }

    public function install()
    {
        $this->identifier = $this->step['identifier'];

        if (isset($this->step['update'])) {
            $this->doUpdate = $this->step['update'] == 1;
        }

        if (isset($this->step['remove_locations'])) {
            $this->doRemoveLocations = $this->step['remove_locations'] == 1;
        }

        $source = isset($this->step['source']) ? $this->step['source'] : '';
        if (!isset($this->step['parent'])) {
            throw new \Exception("Missing parent param");
        }
        $parentNodeId = $this->step['parent'];

        if (strpos($source, 'http') !== false) {
            $this->installFromRemote($source, $parentNodeId);
        } elseif (is_dir($this->ioTools->getDataDir() . "/contenttrees/{$this->identifier}")) {
            $this->installFromLocal($parentNodeId);
        } else {
            $this->logger->error("Content tree $this->identifier not found");
        }
    }

    private function installFromLocal($parentNodeId)
    {
        $needLock = $this->step['lock'] ?? false;
        $lockLog = $needLock ? ' and lock' : '';
        $this->logger->info("Install{$lockLog} contenttree " . $this->identifier . " from " . "contenttrees/{$this->identifier}");

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

        foreach ($contents as $identifier => $content) {
            $this->logger->info(" - $identifier " . $content['metadata']['remoteId']);

            $sortData = false;
            if (isset($content['sort_data'])) {
                $sortData = $content['sort_data'];
                unset($content['sort_data']);
            }

            unset($content['metadata']['id']);
            unset($content['metadata']['class']);
            unset($content['metadata']['sectionIdentifier']);
            unset($content['metadata']['ownerId']);
            unset($content['metadata']['ownerName']);
            unset($content['metadata']['mainNodeId']);
            unset($content['metadata']['published']);
            unset($content['metadata']['modified']);
            unset($content['metadata']['name']);
            unset($content['metadata']['link']);
//            unset($content['metadata']['stateIdentifiers']);
            unset($content['metadata']['assignedNodes']);
            unset($content['metadata']['classDefinition']);
            $payload = new PayloadBuilder($content);

            $payload->setParentNodes([$parentNodeId]);
            $payload->unSetData('image');
            $payload->unSetData('managed_by_area');
            $payload->unSetData('managed_by_political_body');
            $payload->unSetData('help');
            unset($payload['metadata']['assignedNodes']);
            unset($payload['metadata']['classDefinition']);

            $isUpdate = false;
            $alreadyExists = isset($content['metadata']['remoteId']) ?
                eZContentObject::fetchByRemoteID($payload['metadata']['remoteId']) : false;
            if ($alreadyExists) {
                if ($this->doUpdate) {
                    $removeNodeAssignments = [];
                    if ($this->doRemoveLocations) {
                        $this->getLogger()->debug(' -> move in #' . $parentNodeId);
                        \eZContentObjectTreeNodeOperations::move($alreadyExists->mainNodeID(), $parentNodeId);

                        foreach ($alreadyExists->assignedNodes() as $node) {
                            if (!in_array($node->attribute('parent_node_id'), $payload->getMetadaData('parentNodes'))) {
                                $removeNodeAssignments[$node->attribute('node_id')] = $node->fetchParent()->attribute(
                                    'name'
                                );
                            }
                        }
                    }

                    $result = $contentRepository->update($payload->getArrayCopy());
                    $nodeId = $result['content']['metadata']['mainNodeId'];
                    if (count($removeNodeAssignments) > 0) {
                        $this->getLogger()->debug(
                            ' -> remove locations in ' . implode(', ', array_values($removeNodeAssignments))
                        );
                        \eZContentOperationCollection::removeNodes(array_keys($removeNodeAssignments));
                        $nodeId = eZContentObject::fetchByRemoteID($payload['metadata']['remoteId'])->mainNodeID();
                    }
                } else {
                    $this->getLogger()->error(' -> already exists');
                    $node = $alreadyExists->mainNode();
                    if ($node instanceof \eZContentObjectTreeNode) {
                        $nodeId = $node->attribute('node_id');
                    }
                }
                $isUpdate = true;
            } else {
                $result = $contentRepository->create($payload->getArrayCopy());
                $nodeId = $result['content']['metadata']['mainNodeId'];
            }

            $node = \eZContentObjectTreeNode::fetch((int)$nodeId);
            if (!$node instanceof \eZContentObjectTreeNode){
                throw new \Exception("Node $nodeId not found for existing object " . $content['metadata']['remoteId']);
            }

            if ($sortData && (($this->doUpdate && $isUpdate) || !$isUpdate)) {
                $this->setSortAndPriority($node, $sortData);
            }

            $resetFields = $this->step['reset'] ?? [];
            if (count($resetFields) && $isUpdate) {
                $this->resetContentFields($resetFields, $payload, $alreadyExists);
            }

            $this->rename($node);

            $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier . '_node'] = $node->attribute(
                'node_id'
            );
            $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier . '_object'] = $node->attribute(
                'contentobject_id'
            );
            $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier . '_path_string'] = $node->attribute(
                'path_string'
            );

            if ($needLock) {
                $this->lockContentByNode($node);
            }
        }
    }

    private function rename(\eZContentObjectTreeNode $node)
    {
        \eZContentObject::clearCache([$node->attribute('contentobject_id')]);
        $object = \eZContentObject::fetch((int)$node->attribute('contentobject_id'));
        $class = $object->contentClass();
        $object->setName($class->contentObjectName($object));
        $object->store();
    }

    private function installFromRemote($remoteUrl, $parentNodeId)
    {
        $needLock = $this->step['lock'] ?? false;
        $lockLog = $needLock ?? ' and lock';
        $parts = explode('/api/opendata/v2/content/browse/', $remoteUrl);
        $remoteHost = array_shift($parts);
        $root = array_pop($parts);

        $contentRepository = new ContentRepository();
        $contentRepository->setEnvironment(EnvironmentLoader::loadPreset('content'));

        $this->logger->info("Install{$lockLog} contenttree " . $this->identifier . " from $remoteHost");

        $client = new HttpClient($remoteHost);
        $remoteRoot = $client->browse($root, 100);
        foreach ($remoteRoot['children'] as $childNode) {
            try {
                $child = $client->read($childNode['id']);

                $contentNames = $child['metadata']['name'];
                $contentName = current($contentNames);
                $identifier = \Opencontent\Installer\Dumper\Tool::slugize($contentName);
                $this->logger->info(" - $contentName");

                $result = $client->import(
                    $child,
                    $contentRepository,
                    function (PayloadBuilder $payload) use ($parentNodeId) {
                        $payload->setParentNodes([$parentNodeId]);
                        $payload->unSetData('image');
                        $payload->unSetData('managed_by_area');
                        $payload->unSetData('managed_by_political_body');
                        $payload->unSetData('help');
                        unset($payload['metadata']['assignedNodes']);
                        unset($payload['metadata']['classDefinition']);

                        return $payload;
                    }
                );

                $nodeId = $result['content']['metadata']['mainNodeId'];
                $node = \eZContentObjectTreeNode::fetch($nodeId);

                $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier . '_node'] = $node->attribute(
                    'node_id'
                );
                $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier . '_object'] = $node->attribute(
                    'contentobject_id'
                );
                $this->installerVars['contenttree_' . $this->identifier . '_' . $identifier . '_path_string'] = $node->attribute(
                    'path_string'
                );

                if ($needLock){
                    $this->lockContentByNode($node);
                }

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

    public function sync()
    {
        $this->identifier = $this->step['identifier'];
        if (is_dir($this->ioTools->getDataDir() . "/contenttrees/{$this->identifier}")) {
            $files = \eZDir::findSubitems($this->ioTools->getDataDir() . "/contenttrees/{$this->identifier}", 'f');
            foreach ($files as $file) {
                if (\eZFile::suffix($file) == 'yml') {
                    $identifier = substr(basename($file), 0, -4);
                    $filePath = $this->ioTools->getFile("/contenttrees/{$this->identifier}/$file");
                    $definitionData = Yaml::parseFile($filePath);

                    if (isset($definitionData['metadata']['remoteId'])) {
                        $isModified = false;
                        $object = isset($definitionData['metadata']['remoteId']) ? \eZContentObject::fetchByRemoteID(
                            $definitionData['metadata']['remoteId']
                        ) : false;
                        if ($object instanceof \eZContentObject) {
                            $contentData = (array)\Opencontent\Opendata\Api\Values\Content::createFromEzContentObject($object);
                            foreach ($object->dataMap() as $identifier => $attribute) {
                                if (in_array($attribute->attribute('data_type_string'), [
                                    \eZStringType::DATA_TYPE_STRING,
                                    \eZTextType::DATA_TYPE_STRING,
                                ])) {
                                    foreach ($contentData['data'] as $language => $contentDatum) {
                                        if ($language == 'ita-IT') continue;
                                        if (in_array($language, $definitionData['metadata']['languages'])
                                            && $definitionData['data'][$language][$identifier] != $contentDatum[$identifier]['content']) {
                                            $definitionData['data'][$language][$identifier] = $contentDatum[$identifier]['content'];
                                            $isModified = true;
                                        }
                                    }
                                }
                            }
                        }
                        if ($isModified) {
                            file_put_contents($filePath, Yaml::dump($definitionData, 10));
                        }
                    }
                }
            }
        }
    }


}