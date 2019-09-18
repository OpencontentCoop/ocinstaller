<?php

namespace Opencontent\Installer;

use eZRole;

class Role extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function install()
    {
        $identifier = $this->step['identifier'];
        $roleDefinition = $this->ioTools->getJsonContents("roles/{$identifier}.yml");

        $this->logger->info("Install role " . $identifier);

        $name = $roleDefinition['name'];
        $role = eZRole::fetchByName($name);

        if (!$role instanceof eZRole) {
            $role = eZRole::create($name);
            $role->store();
        } else {
            $role->removePolicies();
        }

        foreach ($roleDefinition['policies'] as $policy) {
            $role->appendPolicy($policy['ModuleName'], $policy['FunctionName'], $policy['Limitation']);
        }

        $trans = \eZCharTransform::instance();
        $roleIdentifier = $trans->transformByGroup($name, 'urlalias');
        $this->installerVars['role_' . $roleIdentifier] = $role->attribute('id');

        if (isset($this->step['apply_to'])){
            foreach ($this->step['apply_to'] as $userId){
                $this->logger->info(" - assign to $userId");
                $role->assignToUser($userId);
            }
        }
    }
}