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
        $loadPolicies = isset($this->step['load_policies']) ? $this->step['load_policies'] : true;
        if ($loadPolicies) {
            $roleDefinition = $this->ioTools->getJsonContents("roles/{$identifier}.yml");

            $this->logger->info("Install role " . $identifier);

            $name = $roleDefinition['name'];
            $serializer = new RoleSerializer();
            $role = $serializer->unserialize($roleDefinition);
        } else {
            $name = $this->step['identifier'];
            $roleDefinition = ['policies' => []];
            $role = eZRole::fetchByName($name);
        }

        if (!$role instanceof eZRole) {
            $role = eZRole::create($name);
            $role->store();
        } elseif ($loadPolicies) {
            $role->removePolicies();
        }

        foreach ($roleDefinition['policies'] as $policy) {
            foreach ($policy['Limitation'] as $index => $limitation) {
                $policy['Limitation'][$index] = $this->parseVars($limitation);
            }
            $role->appendPolicy($policy['ModuleName'], $policy['FunctionName'], $policy['Limitation']);
        }

        $roleIdentifier = Tool::slugize($name);
        $this->installerVars['role_' . $roleIdentifier] = $role->attribute('id');

        if (isset($this->step['apply_to'])) {
            foreach ($this->step['apply_to'] as $userId) {
                $this->logger->info(" - assign to $userId");
                if (!is_numeric($userId)) {
                    $object = \eZContentObject::fetchByRemoteID($userId);
                    if ($object instanceof \eZContentObject) {
                        $userId = $object->attribute('id');
                    }
                }
                $role->assignToUser($userId);
            }
        }

        if (isset($this->step['remove_from'])) {
            foreach ($this->step['remove_from'] as $userId) {
                $this->logger->info(" - remove from $userId");
                if (!is_numeric($userId)) {
                    $object = \eZContentObject::fetchByRemoteID($userId);
                    if ($object instanceof \eZContentObject) {
                        $userId = $object->attribute('id');
                    }
                }
                $role->removeUserAssignment($userId);
            }
        }
    }

    private function parseVars($item)
    {
        if (is_array($item)) {
            foreach ($item as $index => $i) {
                $item[$index] = $this->parseVars($i);
            }

            return $item;
        }

        return $this->installerVars->parseVarValue($item);
    }
}