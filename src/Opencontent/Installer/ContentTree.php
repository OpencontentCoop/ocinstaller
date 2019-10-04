<?php

namespace Opencontent\Installer;

use Opencontent\Opendata\Api\ContentRepository;
use Opencontent\Opendata\Api\EnvironmentLoader;
use Opencontent\Opendata\Rest\Client\HttpClient;
use Opencontent\Opendata\Rest\Client\PayloadBuilder;

class ContentTree extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $identifier;

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

        $source = isset($this->step['source']) ? $this->step['source'] : '';
        if (!isset($this->step['parent'])){
            throw new \Exception("Missing parent param");
        }
        $parentNodeId = $this->step['parent'];
        if (strpos($source, 'http') !== false) {
            $this->installFromRemote($source, $parentNodeId);
        } elseif (is_dir($this->ioTools->getDataDir() . "/contenttrees/{$this->identifier}")) {
            $this->installFromLocale($parentNodeId);
        }
    }

    private function installFromLocale($parentNodeId)
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

            $client = new HttpClient('');
            $payload = $client->getPayload($content);
            $payload->setParentNodes([$parentNodeId]);
            $payload->unSetData('image');
            $payload->unSetData('managed_by_area');
            $payload->unSetData('managed_by_political_body');
            $payload->unSetData('help');
            unset($payload['metadata']['assignedNodes']);
            unset($payload['metadata']['classDefinition']);

            $alreadyExists = \eZContentObject::fetchByRemoteID($payload['metadata']['remoteId']);
            if (isset($content['metadata']['remoteId']) && $alreadyExists){
                $content['metadata']['parentNodes'] = [$alreadyExists->mainNode()->attribute('parent_node_id')];
                $result = $contentRepository->update($payload->getArrayCopy());
            }else {
                $result = $contentRepository->create($payload->getArrayCopy());
            }

            $nodeId = $result['content']['metadata']['mainNodeId'];
            $node = \eZContentObjectTreeNode::fetch($nodeId);
            if (!$node instanceof \eZContentObjectTreeNode){
                throw new \Exception("Node $nodeId not found");
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
}