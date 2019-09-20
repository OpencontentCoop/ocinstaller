<?php

namespace Opencontent\Installer;

use Opencontent\Opendata\Api\ContentRepository;
use Opencontent\Opendata\Api\EnvironmentLoader;
use Opencontent\Opendata\Rest\Client\HttpClient;
use Opencontent\Opendata\Rest\Client\PayloadBuilder;

class ContentTree extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun()
    {
        $identifier = $this->step['identifier'];
        $this->logger->info("Install contenttree " . $identifier);
    }

    public function install()
    {
        $this->identifier = $this->step['identifier'];
        $remoteUrl = $this->step['source'];
        $parts = explode('/api/opendata/v2/content/browse/', $remoteUrl);
        $remoteHost = array_shift($parts);
        $root = array_pop($parts);

        $contentRepository = new ContentRepository();
        $contentRepository->setEnvironment(EnvironmentLoader::loadPreset('content'));
        $parentNodeId = $this->step['parent'];

        $this->logger->info("Install contenttree " . $this->identifier . " from $remoteHost");

        $client = new HttpClient($remoteHost);
        $remoteRoot = $client->browse($root);
        foreach ($remoteRoot['children'] as $childNode) {
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
        }

    }
}