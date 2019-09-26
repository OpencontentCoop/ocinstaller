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
        $identifier = $this->step['identifier'];
        $this->logger->info("Install contenttree " . $identifier);
    }

    public function install()
    {
        $this->identifier = $this->step['identifier'];

        $source = isset($this->step['source']) ? $this->step['source'] : '';
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
                $contents[] = $this->ioTools->getJsonContents("/contenttrees/{$this->identifier}/$file");
            }
        }

        $contentRepository = new ContentRepository();
        $contentRepository->setEnvironment(EnvironmentLoader::loadPreset('content'));

        foreach ($contents as $content){
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
                $contentRepository->update($payload->getArrayCopy());
            }else {
                $contentRepository->create($payload->getArrayCopy());
            }
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
                $this->logger->info(" - $contentName");

                $client->import($child, $contentRepository, function (PayloadBuilder $payload) use ($parentNodeId) {
                    $payload->setParentNodes([$parentNodeId]);
                    $payload->unSetData('image');
                    $payload->unSetData('managed_by_area');
                    $payload->unSetData('managed_by_political_body');
                    $payload->unSetData('help');
                    unset($payload['metadata']['assignedNodes']);
                    unset($payload['metadata']['classDefinition']);

                    return $payload;
                });
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
            }
        }
    }
}