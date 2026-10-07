<?php

namespace Opencontent\Installer;

use eZContentClass;
use eZContentObject;
use eZPersistentObject;
use eZSolr;

class Reindex extends AbstractStepInstaller implements InterfaceStepInstaller
{
    const DEFAULT_BATCH_SIZE = 50;

    const LOG_EVERY_BATCHES = 10;

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

        $ids = $this->fetchPublishedObjectIds((int)$class->attribute('id'));
        $total = count($ids);
        $this->logger->info("Reindex $identifier objects: $total");

        if ($total === 0) {
            return;
        }

        // Gli oggetti vengono caricati uno alla volta e la cache in memoria di eZ viene svuotata
        // a ogni blocco: tenere in memoria tutti gli oggetti esaurisce il memory_limit su classi popolose.
        $batchSize = $this->getBatchSize();
        $searchEngine = new eZSolr();
        $processed = 0;
        $missing = [];
        $failed = [];

        foreach (array_chunk($ids, $batchSize) as $batchIndex => $batch) {
            foreach ($batch as $id) {
                $object = eZContentObject::fetch($id);
                if (!$object instanceof eZContentObject) {
                    $missing[] = $id;
                    continue;
                }
                if ($searchEngine->addObject($object, false) === false) {
                    $failed[] = $id;
                }
                unset($object);
            }

            $searchEngine->commit();
            eZContentObject::clearCache();

            $processed += count($batch);
            if (($batchIndex + 1) % self::LOG_EVERY_BATCHES === 0 || $processed === $total) {
                $this->logger->info(sprintf(
                    " - %d/%d (memory %.1f MB)",
                    $processed,
                    $total,
                    memory_get_usage(true) / 1048576
                ));
            }
        }

        if (count($missing) > 0) {
            $this->logger->warning("Reindex $identifier: objects not found: " . implode(', ', $missing));
        }
        if (count($failed) > 0) {
            $this->logger->warning("Reindex $identifier: indexing failed for objects: " . implode(', ', $failed));
        }
    }

    /**
     * @return int[]
     */
    private function fetchPublishedObjectIds(int $classId): array
    {
        $rows = eZPersistentObject::fetchObjectList(
            eZContentObject::definition(),
            ['id'],
            ['contentclass_id' => $classId, 'status' => eZContentObject::STATUS_PUBLISHED],
            ['id' => 'asc'],
            null,
            false
        );

        $ids = [];
        foreach ((array)$rows as $row) {
            $ids[] = (int)$row['id'];
        }

        return $ids;
    }

    private function getBatchSize(): int
    {
        $batchSize = isset($this->step['batch_size']) ? (int)$this->step['batch_size'] : self::DEFAULT_BATCH_SIZE;

        return $batchSize > 0 ? $batchSize : self::DEFAULT_BATCH_SIZE;
    }
}
