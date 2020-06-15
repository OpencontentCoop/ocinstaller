<?php

namespace Opencontent\Installer;

use eZContentObject;

class PatchContent extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun()
    {
        $identifier = $this->step['identifier'];
        $object = eZContentObject::fetchByRemoteID($identifier);
        if (!$object instanceof eZContentObject){
            throw new \Exception("Content $identifier not found");
        }
        $dataMap = $object->dataMap();
        $fields = $this->step['attributes'];
        foreach ($fields as $field => $value){
            if (!isset($dataMap[$field])){
                throw new \Exception("Attribute $field not found");
            }
        }
        $this->logger->info("Patch content " . $identifier);

    }
    
    public function install()
    {
        $identifier = $this->step['identifier'];
        $object = eZContentObject::fetchByRemoteID($identifier);
        if (!$object instanceof eZContentObject){
            throw new \Exception("Content $identifier not found");
        }
        $dataMap = $object->dataMap();
        $fields = $this->step['attributes'];
        foreach ($fields as $field => $value){
            if (!isset($dataMap[$field])){
                throw new \Exception("Attribute $field not found");
            }
        }
        \eZContentFunctions::updateAndPublishObject($object, ['attributes' => $fields]);
        $this->logger->info("Patch content " . $identifier);
    }
}