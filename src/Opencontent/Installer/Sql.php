<?php

namespace Opencontent\Installer;
use eZDB;

class Sql extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun(): void
    {
        $identifier = $this->step['identifier'];
        if ($this->step['type'] == 'sql_copy_from_tsv'){
            $file = $this->ioTools->getFile("sql/{$identifier}.tsv");
            $this->logger->info("Install sql from tsv $file in " . $this->step['table']);
        }else {
            $sql = $this->ioTools->getFileContents("sql/{$identifier}.sql");
            $this->logger->info("Install sql $sql");
        }
    }

    public function install(): void
    {
        $identifier = $this->step['identifier'];
        if ($this->step['type'] == 'sql_copy_from_tsv'){
            if (!isset($this->step['table'])){
                throw new \Exception("Missing table parameter");
            }
            $file = $this->ioTools->getFile("sql/{$identifier}.tsv");
            $this->logger->info("Install sql from tsv $file in " . $this->step['table']);

            $rows = explode("\n", $this->ioTools->getFileContents("sql/{$identifier}.tsv"));
            if (empty($rows)){
                throw new \Exception("Empty data in tsv $file");
            }
            pg_copy_from(eZDB::instance()->DBConnection, $this->step['table'], $rows);

        }else {
            $sql = $this->ioTools->getFileContents("sql/{$identifier}.sql");
            $this->logger->info("Install sql $sql");
            eZDB::instance()->query($sql);
        }
    }
}