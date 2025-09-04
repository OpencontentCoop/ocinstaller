<?php

namespace Opencontent\Installer;

use Opencontent\Opendata\Api\ContentRepository;
use Opencontent\Opendata\Api\EnvironmentLoader;

class ImagesFromUrl extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun(): void
    {
        $urlList = (array)$this->step['urls'];
        if (!empty($urlList)) {
            foreach ($urlList as $url) {
                $name = $this->parseName(basename($url));
                $remoteId = $this->parseRemoteId(basename($url));
                $alreadyExists = \eZContentObject::fetchByRemoteID($remoteId);
                if ($alreadyExists) {
                    $this->logger->info("Update image $remoteId  from $url");
                }else {
                    $this->logger->info("Install image $remoteId from $url");
                }
                $this->installerVars[$remoteId] = 0;
            }
        }
    }

    public function install(): void
    {
        $doUpdate = isset($this->step['update']) && $this->step['update'] == 1;

        $urlList = (array)$this->step['urls'];
        if (!empty($urlList)) {
            foreach ($urlList as $url) {
                $name = $this->parseName(basename($url));
                $remoteId = $this->parseRemoteId(basename($url));
                $alreadyExists = \eZContentObject::fetchByRemoteID($remoteId);

                $contentRepository = new ContentRepository();
                $contentRepository->setEnvironment(EnvironmentLoader::loadPreset('content'));
                $content = [
                    'metadata' => [
                        'remoteId' => $remoteId,
                        'classIdentifier' => 'image',
                        'parentNodes' => $this->step['parent'],
                    ],
                    'data' => [
                        'name' => $name,
                        'image' => [
                            'filename' => basename($url),
                            'url' => $url,
                        ],
                    ],
                ];

                foreach ($this->step['attributes'] as $identifier => $value){
                    $content['data'][$identifier] = $value;
                }

                if ($alreadyExists) {
                    $this->logger->info("Update image $remoteId from $url");
                    if ($doUpdate) {
                        $result = $contentRepository->update($content);
                        $nodeId = $result['content']['metadata']['mainNodeId'];
                    } else {
                        $this->getLogger()->info(' -> already exists');
                        $nodeId = $alreadyExists->mainNode()->attribute('node_id');
                    }
                } else {
                    $this->logger->info("Install image $remoteId from $url");
                    $result = $contentRepository->create($content);
                    $nodeId = $result['content']['metadata']['mainNodeId'];
                }
                $node = \eZContentObjectTreeNode::fetch($nodeId);
                if (!$node instanceof \eZContentObjectTreeNode){
                    throw new \Exception("Node $nodeId not found");
                }
                $this->installerVars[$remoteId] = $nodeId;
            }
        }
    }

    private function parseName($string)
    {
        $string = str_replace('-', ' ', $string);
        $string = str_replace('_', ' ', $string);
        $string = ucfirst($string);

        return $string;
    }

    private function parseRemoteId($string)
    {
        $string = $this->parseName($string);
        $parts = explode('.', $string);

        return 'img-' . strtolower(\eZCharTransform::instance()->transformByGroup($parts[0], 'urlalias'));
    }
}