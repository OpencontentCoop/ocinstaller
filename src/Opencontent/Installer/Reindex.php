<?php

namespace Opencontent\Installer;

use eZContentClass;
use eZContentObject;
use eZPersistentObject;
use eZSolr;

class Reindex extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun(): void
    {
        $identifier = $this->step['identifier'];
        if (!eZContentClass::fetchByIdentifier($identifier)) {
            throw new \Exception("Class $identifier not found");
        }
        $this->logger->info("Reindex $identifier objects");
    }

    public function install(): void
    {
        $identifier = $this->step['identifier'];
        $class = eZContentClass::fetchByIdentifier($identifier);
        if (!$class instanceof eZContentClass) {
            throw new \Exception("Class $identifier not found");
        }

        $objects = eZPersistentObject::fetchObjectList(eZContentObject::definition(),
            null,
            ['contentclass_id' => $class->attribute('id'), 'status' => eZContentObject::STATUS_PUBLISHED]
        );

        $this->logger->info("Reindex $identifier objects: " . count($objects));

        $searchEngine = new eZSolr();
        foreach ($objects as $object) {
            $searchEngine->addObject($object, false);
        }
        $searchEngine->commit();
    }
}