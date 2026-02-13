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
        $stateTools = new OpenPAStateTools();
        $mergedRules = [
            'ruleDefinitions' => array_merge($stateTools->getRuleDefinitions(), $definition['ruleDefinitions']),
            'ruleApplications' => array_replace_recursive($stateTools->getRuleApplications(), $definition['ruleApplications']),
        ];
        $this->logger->info("Install $identifier change state rules");
        OpenPAStateTools::storeRulesBackup();
        $stateTools->store($mergedRules);
    }

}