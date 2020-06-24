<?php

namespace Opencontent\Installer;

use eZContentObject;

class PatchContent extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun()
    {
        $identifier = $this->step['identifier'];
        $object = eZContentObject::fetchByRemoteID($identifier);
        if (!$object instanceof eZContentObject) {
            throw new \Exception("Content $identifier not found");
        }
        $dataMap = $object->dataMap();
        $fields = $this->step['attributes'];
        foreach ($fields as $field => $value) {
            if (!isset($dataMap[$field])) {
                throw new \Exception("Attribute $field not found");
            }
        }
        $this->logger->info("Patch content " . $identifier);

    }

    public function install()
    {
        $identifier = $this->step['identifier'];
        $this->logger->info("Patch content " . $identifier);
        $object = eZContentObject::fetchByRemoteID($identifier);
        if (!$object instanceof eZContentObject) {
            throw new \Exception("Content $identifier not found");
        }
        $dataMap = $object->dataMap();
        $fields = $this->step['attributes'];
        foreach ($fields as $field => $value) {
            if (!isset($dataMap[$field])) {
                throw new \Exception("Attribute $field not found");
            }
        }
        if (count($fields) > 0) {
            $this->updateAndPublishObject($object, ['attributes' => $fields]);
        }

        if (isset($this->step['sort_data'])){
            $sortData = $this->step['sort_data'];
            $this->setSortAndPriority($object->mainNode(), $sortData);
        }
    }

    private function setSortAndPriority(\eZContentObjectTreeNode $node, $data)
    {
        if (isset($data['sort_field'])){
            $node->setAttribute('sort_field', $data['sort_field']);
        }

        if (isset($data['sort_order'])) {
            $node->setAttribute('sort_order', $data['sort_order']);
        }

        if (isset($data['priority'])) {
            $node->setAttribute('priority', $data['priority']);
        }

        $node->store();
    }

    private function updateAndPublishObject(eZContentObject $object, array $params)
    {
        \eZModule::setGlobalPathList(
            \eZINI::instance('module.ini')->variable('ModuleSettings', 'ModuleRepositories')
        );

        // avoid php notice in kernel/common/ezmoduleparamsoperator.php on line 71
        if (!isset($GLOBALS['eZRequestedModuleParams'])) {
            $GLOBALS['eZRequestedModuleParams'] = [
                'module_name' => null,
                'function_name' => null,
                'parameters' => null
            ];
        }

        if (!array_key_exists('attributes', $params) and !is_array($params['attributes']) and count($params['attributes']) > 0) {
            eZDebug::writeError('No attributes specified for object' . $object->attribute('id'), __METHOD__);
            return false;
        }

        $storageDir = '';
        $languageCode = false;
        $mustStore = false;

        if (array_key_exists('remote_id', $params)) {
            $object->setAttribute('remote_id', $params['remote_id']);
            $mustStore = true;
        }

        if (array_key_exists('section_id', $params)) {
            $object->setAttribute('section_id', $params['section_id']);
            $mustStore = true;
        }

        if ($mustStore)
            $object->store();

        if (array_key_exists('storage_dir', $params))
            $storageDir = $params['storage_dir'];

        if (array_key_exists('language', $params) and $params['language'] != false) {
            $languageCode = $params['language'];
        } else {
            $initialLanguageID = $object->attribute('initial_language_id');
            $language = \eZContentLanguage::fetch($initialLanguageID);
            $languageCode = $language->attribute('locale');
        }

        $this->db->begin();

        $newVersion = $object->createNewVersion(false, true, $languageCode);

        if (!$newVersion instanceof \eZContentObjectVersion) {
            $this->db->rollback();
            throw new \Exception('Unable to create a new version for object ' . $object->attribute('id'));
        }

        $newVersion->setAttribute('modified', time());
        $newVersion->store();

        $attributeList = $newVersion->attribute('contentobject_attributes');

        $attributesData = $params['attributes'];

        foreach ($attributeList as $attribute) {
            $attributeIdentifier = $attribute->attribute('contentclass_attribute_identifier');
            if (array_key_exists($attributeIdentifier, $attributesData)) {
                $dataString = $attributesData[$attributeIdentifier];
                switch ($datatypeString = $attribute->attribute('data_type_string')) {
                    case 'ezimage':
                    case 'ezbinaryfile':
                    case 'ezmedia':
                    {
                        $dataString = $storageDir . $dataString;
                        break;
                    }
                    default:
                }

                $attribute->fromString($dataString);
                $attribute->store();
            }
        }

        $this->db->commit();

        $operationResult = \eZOperationHandler::execute('content', 'publish', [
            'object_id' => $newVersion->attribute('contentobject_id'),
            'version' => $newVersion->attribute('version')
        ]);

        if ($operationResult['status'] == \eZModuleOperationInfo::STATUS_CONTINUE) {
            return true;
        }

        throw new \Exception("Update status is " . var_export($operationResult['status'], true));
    }
}