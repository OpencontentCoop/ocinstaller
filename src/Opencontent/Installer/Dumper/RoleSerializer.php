<?php

namespace Opencontent\Installer\Dumper;

use eZRole;
use eZPolicy;
use eZPolicyLimitation;
use Exception;
use eZContentClass;
use eZContentObjectTreeNode;
use eZSection;
use eZContentObjectState;

class RoleSerializer implements \JsonSerializable
{
    /**
     * @var array
     */
    private $data;

    /**
     * @var string[]
     */
    private $warnings = array();

    public static function fromString($roleName, $string)
    {
        $data = json_decode($string, true);
        $instance = new static($roleName);
        $instance->setData($data);

        return $instance;
    }

    function jsonSerialize()
    {
        return $this->data;
    }

    function setData($data)
    {
        $this->data = $data;
    }

    /**
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    public function fromRoleName($roleName)
    {
        $this->data = array();
        $role = eZRole::fetchByName($roleName);
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

    private function parseLimitationValues($identifier, $values)
    {
        switch ($identifier) {
            case 'ParentClass':
            case 'Class':
                $classIdentifiers = array();
                foreach ($values as $value) {
                    $classIdentifier = eZContentClass::classIdentifierByID($value);
                    if ($classIdentifier) {
                        $classIdentifiers[] = $classIdentifier;
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
                        $nodes[] = $this->serializeNode($node);
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
                        $nodes[] = $this->serializeNode($node);
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
                        $sectionIdentifiers[] = $section->attribute('identifier');
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
                            $stateIdentifiers[] = $state->attribute('identifier');
                        } else {
                            throw new Exception("$identifier $value not found");
                        }
                    }

                    return $stateIdentifiers;
                }

                return $values;
        }
    }

    private function serializeNode(eZContentObjectTreeNode $node)
    {
        return array(
            'node_id' => $node->attribute('node_id'),
            'name' => $node->attribute('name'),
            'node_remote_id' => $node->attribute('remote_id'),
            'object_id' => $node->attribute('contentobject_id'),
            'object_remote_id' => $node->attribute('object')->attribute('remote_id'),
        );
    }

}