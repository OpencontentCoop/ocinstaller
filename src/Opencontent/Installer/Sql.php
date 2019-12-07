<?php

namespace Opencontent\Installer;
use eZDB;

class Sql extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun()
    {
        $identifier = $this->step['identifier'];
        $sql = $this->ioTools->getJsonContents("sql/{$identifier}.sql");
        $this->logger->info("Install sql $sql");
    }

    public function install()
    {
        $identifier = $this->step['identifier'];
        $sql = $this->ioTools->getJsonContents("sql/{$identifier}.sql");
        $this->logger->info("Install sql $sql");
        eZDB::instance()->query($sql);
    }
}