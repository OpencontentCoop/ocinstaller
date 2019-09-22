<?php

namespace Opencontent\Installer\Serializer;

use eZRole;
use eZPolicy;
use eZPolicyLimitation;
use Exception;
use eZContentClass;
use eZContentObjectTreeNode;
use eZSection;
use eZContentObjectState;
use Opencontent\Installer\Dumper\Tool;
use Symfony\Component\Yaml\Yaml;

class RoleSerializer
{
    /**
     * @var array
     */
    private $data;

    /**
     * @var string[]
     */
    private $warnings = array();

    /**
     * @param eZRole|null $role
     * @return array
     */
    public function serialize(eZRole $role)
    {
        $this->data = array();
        if ($role instanceof eZRole) {
            $this->data['name'] = $role->attribute('name');
            $policies = array();
            /** @var eZPolicy $policy */
            foreach ($role->attribute('policies') as $policy) {
                $item = array(
                    'ModuleName' => $policy->attribute('module_name'),
                    'FunctionName' => $policy->attribute('function_name'),
                    'Limitation' => array(),
                );
                /** @var eZPolicyLimitation $limitation */
                foreach ($policy->attribute('limitations') as $limitation) {
                    try {
                        $parsedValues = $this->parseLimitationValues(
                            $limitation->attribute('identifier'),
                            $limitation->attribute('values_as_array')
                        );
                        $item['Limitation'][$limitation->attribute('identifier')] = $parsedValues;
                    } catch (Exception $e) {
                        $this->warnings[] = $e->getMessage()
                            . ' in ' . $item['ModuleName'] . '/' . $item['FunctionName'] . ' '
                            . $limitation->attribute('identifier') . '(' . $limitation->attribute('values_as_string') . ')';
                    }
                }

                $policies[] = $item;
            }

            $this->data['policies'] = $policies;
        }

        if (!empty($this->warnings)) {
            $this->data['warnings'] = $this->warnings;
        }

        return $this->data;
    }

    /**
     * @param array $roleDefinition
     * @return eZRole
     */
    public function unserialize(array $roleDefinition)
    {
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

        return $role;
    }

    public function serializeToYaml(eZRole $role, $targetDir)
    {
        $data = $this->serialize($role);

        $roleName = Tool::slugize($role->attribute('name'));
        $filename = $roleName . '.yml';

        $dataYaml = Yaml::dump($data, 10);
        $directory = rtrim($targetDir, '/') . '/roles';

        \eZDir::mkdir($directory, false, true);
        \eZFile::create($filename, $directory, $dataYaml);

        return $roleName;
    }

    private function parseLimitationValues($identifier, $values)
    {
        switch ($identifier) {
            case 'ParentClass':
            case 'Class':
                $classIdentifiers = array();
                foreach ($values as $value) {
                    $classIdentifier = eZContentClass::classIdentifierByID($value);
                    if ($classIdentifier) {
                        $classIdentifiers[] = '$class_' . $classIdentifier;
                    } else {
                        throw new Exception("$identifier $value not found");
                    }
                }

                return $classIdentifiers;
                break;

            case 'Node':
                $nodes = array();
                foreach ($values as $value) {
                    $node = eZContentObjectTreeNode::fetch($value);
                    if ($node) {
                        $name =  Tool::slugize($node->attribute('name'));
                        $nodes[] = '$content_' . $name . '__node';
                    } else {
                        throw new Exception("$identifier $value not found");
                    }
                }

                return $nodes;
                break;

            case 'Subtree':
                $nodes = array();
                foreach ($values as $value) {
                    $node = eZContentObjectTreeNode::fetchByPath($value);
                    if ($node) {
                        $name = Tool::slugize( $node->attribute('name'));
                        $nodes[] = '$content_' . $name . '__node';
                    } else {
                        throw new Exception("$identifier $value not found");
                    }
                }

                return $nodes;
                break;

            case 'Section':
                $sectionIdentifiers = array();
                foreach ($values as $value) {
                    $section = eZSection::fetch($value);
                    if ($section instanceof eZSection) {
                        $sectionIdentifiers[] = '$section_' . $section->attribute('identifier');
                    } else {
                        throw new Exception("$identifier $value not found");
                    }
                }

                return $sectionIdentifiers;
                break;

            default:

                if (strpos($identifier, 'StateGroup_') === 0 || $identifier == 'NewState') {
                    $stateIdentifiers = array();
                    foreach ($values as $value) {
                        $state = eZContentObjectState::fetchById($value);
                        if ($state) {
                            $groupIdentifier = str_replace('StateGroup_', '', $identifier);
                            $stateIdentifiers[] = '$state_' . $groupIdentifier . '_' . $state->attribute('identifier');
                        } else {
                            throw new Exception("$identifier $value not found");
                        }
                    }

                    return $stateIdentifiers;
                }

                return $values;
        }
    }

    /**
     * @return string[]
     */
    public function getWarnings()
    {
        return $this->warnings;
    }

}