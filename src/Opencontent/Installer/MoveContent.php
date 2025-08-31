<?php

namespace Opencontent\Installer;

use eZContentObject;
use Opencontent\Opendata\Api\ContentRepository;

class MoveContent extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun(): void
    {
        $identifier = $this->step['identifier'];
        $moveTo = $this->step['target'];
        $object = eZContentObject::fetchByRemoteID($identifier);
        if (!$object instanceof eZContentObject) {
            throw new \Exception("Content $identifier not found");
        }
        $parentObject = eZContentObject::fetchByRemoteID($moveTo);
        if (!$parentObject instanceof eZContentObject) {
            throw new \Exception("Content $moveTo not found");
        }
        $parentNode = $parentObject->mainNode();
        if (!$parentNode instanceof \eZContentObjectTreeNode) {
            throw new \Exception("Node of content $moveTo not found");
        }
        $this->logger->info("Move " . $object->attribute('name') . " content to " . $parentNode->attribute('name'));
    }

    public function install(): void
    {
        $identifier = $this->step['identifier'];
        $moveTo = $this->step['target'];
        $object = eZContentObject::fetchByRemoteID($identifier);
        if (!$object instanceof eZContentObject) {
            throw new \Exception("Content $identifier not found");
        }
        $parentObject = eZContentObject::fetchByRemoteID($moveTo);
        if (!$parentObject instanceof eZContentObject) {
            throw new \Exception("Content $moveTo not found");
        }
        $parentNode = $parentObject->mainNode();
        if (!$parentNode instanceof \eZContentObjectTreeNode) {
            throw new \Exception("Node of content $moveTo not found");
        }
        $this->logger->info("Move " . $object->attribute('name') . " content to " . $parentNode->attribute('name'));

        $repo = new ContentRepository();
        $repo->move($identifier, $parentNode->attribute('node_id'), true);
    }

}