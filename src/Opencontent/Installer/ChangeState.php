<?php

namespace Opencontent\Installer;

use Opencontent\Installer\Dumper\Tool;
use OpenPAStateTools;

class ChangeState extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun(): void
    {
        $identifier = $this->step['identifier'];
        $definition = $this->ioTools->getJsonContents("changestate/{$identifier}.yml");
        $this->logger->info("Install $identifier change state rules");
    }

    public function install(): void
    {
        $identifier = $this->step['identifier'];
        $definition = $this->ioTools->getJsonContents("changestate/{$identifier}.yml", null, [
            'merge_numeric_keys' => true,
            'unique_values' => true,
        ]);
        $this->logger->info("Install $identifier change state rules");
        $stateTools = new OpenPAStateTools();
        OpenPAStateTools::storeRulesBackup();
        $stateTools->store($definition);
    }

}