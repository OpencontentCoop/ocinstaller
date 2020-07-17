<?php

namespace Opencontent\Installer;

use Opencontent\Installer\Dumper\Tool;
use OpenPASectionTools;

class ChangeSection extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun()
    {
        $identifier = $this->step['identifier'];
        $definition = $this->ioTools->getJsonContents("changesection/{$identifier}.yml");
        $this->logger->info("Install $identifier change section rules");
    }

    public function install()
    {
        $identifier = $this->step['identifier'];
        $definition = $this->ioTools->getJsonContents("changesection/{$identifier}.yml");
        $this->logger->info("Install $identifier change section rules");
        $sectionTools = new OpenPASectionTools();
        OpenPASectionTools::storeBackup();
        $sectionTools->store($definition);
    }

}