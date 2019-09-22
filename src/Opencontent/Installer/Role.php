<?php

namespace Opencontent\Installer;

use eZRole;
use Opencontent\Installer\Dumper\Tool;
use Opencontent\Installer\Serializer\RoleSerializer;

class Role extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun()
    {
        $identifier = $this->step['identifier'];
        $roleDefinition = $this->ioTools->getJsonContents("roles/{$identifier}.yml");
        $name = $roleDefinition['name'];
        $roleIdentifier = Tool::slugize($name);

        $this->logger->info("Install role " . $identifier);
        $this->installerVars['role_' . $roleIdentifier] = 0;
    }

    public function install()
    {
        $identifier = $this->step['identifier'];
        $roleDefinition = $this->ioTools->getJsonContents("roles/{$identifier}.yml");

        $this->logger->info("Install role " . $identifier);

        $name = $roleDefinition['name'];
        $serializer = new RoleSerializer();
        $role = $serializer->unserialize($roleDefinition);

        if (!$role instanceof eZRole) {
            $role = eZRole::create($name);
            $role->store();
        } else {
            $role->removePolicies();
        }

        foreach ($roleDefinition['policies'] as $policy) {
            $role->appendPolicy($policy['ModuleName'], $policy['FunctionName'], $policy['Limitation']);
        }

        $roleIdentifier = Tool::slugize($name);
        $this->installerVars['role_' . $roleIdentifier] = $role->attribute('id');

        if (isset($this->step['apply_to'])){
            foreach ($this->step['apply_to'] as $userId){
                $this->logger->info(" - assign to $userId");
                $role->assignToUser($userId);
            }
        }
    }
}