<?php

namespace Opencontent\Installer;

use eZDbSchema;
use eZExtension;
use eZLocale;
use eZContentLanguage;

class Schema extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $cleanDb;

    private $installBaseSchema;

    private $installExtensionsSchema;

    private $primaryLanguageCode;

    private $extraLanguageCodes;

    private $cleanDataDirectory;
    
    public function __construct($cleanDb, $installBaseSchema, $installExtensionsSchema, $languageList, $cleanDataDirectory)
    {
        $this->cleanDb = $cleanDb;
        $this->installBaseSchema = $installBaseSchema;
        $this->installExtensionsSchema = $installExtensionsSchema;
        $this->primaryLanguageCode = array_shift($languageList);
        $this->extraLanguageCodes = $languageList;

        $cleanDataDirectoryDefault = 'vendor/opencontent/ocinstaller/cleandata';
        $this->cleanDataDirectory = $cleanDataDirectory ? $cleanDataDirectory : $cleanDataDirectoryDefault;

    }

    public function install()
    {
        if ($this->cleanDb) {
            $this->cleanup();
        }

        if ($this->installBaseSchema) {

            $baseSchema = $this->cleanDataDirectory . '/db_schema.dba';
            $baseData = $this->cleanDataDirectory . '/db_data.dba'; //admin change_password

            $this->installSchemaAndData($baseSchema, $baseData);

            if ($this->installExtensionsSchema) {

                $activeExtensions = ['ezmbpaex'];

                $activeExtensions = array_merge(
                    $activeExtensions,
                    eZExtension::activeExtensions()
                );
            }
            $this->installExtensionsSchema($activeExtensions);

            $this->setLanguages($this->primaryLanguageCode, $this->extraLanguageCodes);
            $this->expiryPassword();
        }

    }

    private function cleanup()
    {
        $this->logger->log('Cleanup db');
        $relationTypes = $this->db->supportedRelationTypes();
        $result = true;
        $matchRegexp = "#^ez|^sql|^oc|^cjw|tmp_notification_rule_s#";
        foreach ($relationTypes as $relationType) {
            $relationItems = $this->db->relationList($relationType);
            foreach ($relationItems as $relationItem) {
                if ($matchRegexp !== false and
                    !preg_match($matchRegexp, $relationItem))
                    continue;

                if (!$this->db->removeRelation($relationItem, $relationType)) {
                    $result = false;
                    break;
                }
            }
            if (!$result)
                break;
        }
        return $result;
    }

    private function installSchemaAndData($baseSchema, $baseData)
    {
        $this->logger->log("Install schema " . $baseSchema);
        $schemaArray = eZDbSchema::read($baseSchema, true);

        $this->logger->log("Install schema " . $baseData);
        $dataArray = eZDbSchema::read($baseData, true);

        $schemaArray = array_merge($schemaArray, $dataArray);
        $schemaArray['type'] = strtolower($this->db->databaseName());
        $schemaArray['instance'] = $this->db;

        $dbSchema = eZDbSchema::instance($schemaArray);
        $params = array(
            'schema' => true,
            'data' => true
        );

        if (!$dbSchema->insertSchema($params)) {
            throw new Exception("Unknown error");
        }
    }

    private function installExtensionsSchema(array $activeExtensions = [], array $excludeExtensionList = [])
    {
        $extensionsDir = eZExtension::baseDirectory();
        foreach (array_unique($activeExtensions) as $activeExtension) {
            if (in_array($activeExtension, $excludeExtensionList)) {
                continue;
            }
            $extensionSchema = $extensionsDir . '/' . $activeExtension . '/share/db_schema.dba';

            if (file_exists($extensionSchema)) {

                $this->logger->log("Install schema " . $extensionSchema);

                $extensionSchemaArray = eZDbSchema::read($extensionSchema, true);
                $extensionSchemaArray['type'] = strtolower($this->db->databaseName());
                $extensionSchemaArray['instance'] = $this->db;

                $dbSchema = eZDbSchema::instance($extensionSchemaArray);
                $params = array(
                    'schema' => true,
                    'data' => true
                );

                if (!$dbSchema->insertSchema($params)) {
                    throw new Exception("Unknown error");
                }
            }
        }
    }

    private function setLanguages($primaryLanguageCode, $extraLanguageCodes = [])
    {
        $primaryLanguage = eZLocale::create($primaryLanguageCode);
        $primaryLanguageLocaleCode = $primaryLanguage->localeCode();
        $primaryLanguageName = $primaryLanguage->languageName();

        // Make sure objects use the selected main language instead of eng-GB
        if ($primaryLanguageLocaleCode != 'eng-GB') {
            $this->logger->log("Set primary content language " . $primaryLanguageLocaleCode);

            $extraLanguageCodes[] = 'eng-GB';

            $engLanguageObj = eZContentLanguage::fetchByLocale('eng-GB');
            if (!$engLanguageObj) {
                $engLanguage = eZLocale::create('eng-GB');
                $engLanguageLocaleCode = $engLanguage->localeCode();
                $engLanguageName = $engLanguage->languageName();
                $engLanguageObj = eZContentLanguage::addLanguage($engLanguageLocaleCode, $engLanguageName);
            }
            $engLanguageID = (int)$engLanguageObj->attribute('id');
            $updateSql = "UPDATE ezcontent_language
SET
locale='$primaryLanguageLocaleCode',
name='$primaryLanguageName'
WHERE
id=$engLanguageID";
            $this->db->query($updateSql);
            eZContentLanguage::expireCache();
            $primaryLanguageObj = eZContentLanguage::fetchByLocale($primaryLanguageLocaleCode);
            // Add it if it is missing (most likely)
            if (!$primaryLanguageObj) {
                $primaryLanguageObj = eZContentLanguage::addLanguage($primaryLanguageLocaleCode, $primaryLanguageName);
            }

            $primaryLanguageID = (int)$primaryLanguageObj->attribute('id');

            // Find objects which are always available
            $sql = "SELECT id
FROM
ezcontentobject
WHERE
language_mask & 1 = 1";

            $objectList = array();
            $list = $this->db->arrayQuery($sql);
            foreach ($list as $row) {
                $objectList[] = (int)$row['id'];
            }
            $inSql = 'IN ( ' . implode(', ', $objectList) . ')';

            // Updates databases that have eng-GB data to the new locale.
            $updateSql = "UPDATE ezcontentobject_name
SET
content_translation='$primaryLanguageLocaleCode',
real_translation='$primaryLanguageLocaleCode',
language_id=$primaryLanguageID
WHERE
content_translation='eng-GB' OR
real_translation='eng-GB'";
            $this->db->query($updateSql);
            // Fix always available
            $updateSql = "UPDATE ezcontentobject_name
SET
language_id=language_id+1
WHERE
contentobject_id $inSql";
            $this->db->query($updateSql);

            // attributes
            $updateSql = "UPDATE ezcontentobject_attribute
SET
language_code='$primaryLanguageLocaleCode',
language_id=$primaryLanguageID
WHERE
language_code='eng-GB'";
            $this->db->query($updateSql);
            // Fix always available
            $updateSql = "UPDATE ezcontentobject_attribute
SET
language_id=language_id+1
WHERE
contentobject_id $inSql";
            $this->db->query($updateSql);

            // version
            $updateSql = "UPDATE ezcontentobject_version
SET
initial_language_id=$primaryLanguageID,
language_mask=$primaryLanguageID
WHERE
initial_language_id=$engLanguageID";
            $this->db->query($updateSql);
            // Fix always available
            $updateSql = "UPDATE ezcontentobject_version
SET
language_mask=language_mask+1
WHERE
contentobject_id $inSql";
            $this->db->query($updateSql);

            // object
            $updateSql = "UPDATE ezcontentobject
SET
initial_language_id=$primaryLanguageID,
language_mask=$primaryLanguageID
WHERE
initial_language_id=$engLanguageID";
            $this->db->query($updateSql);
            // Fix always available
            $updateSql = "UPDATE ezcontentobject
SET
language_mask=language_mask+1
WHERE
id $inSql";
            $this->db->query($updateSql);

            // content object state groups & states
            $mask = $primaryLanguageID | 1;

            $this->db->query("UPDATE ezcobj_state_group
                         SET language_mask = $mask, default_language_id = $primaryLanguageID
                         WHERE default_language_id = $engLanguageID");

            $this->db->query("UPDATE ezcobj_state
                         SET language_mask = $mask, default_language_id = $primaryLanguageID
                         WHERE default_language_id = $engLanguageID");

            $this->db->query("UPDATE ezcobj_state_group_language
                         SET language_id = $primaryLanguageID
                         WHERE language_id = $engLanguageID");

            $this->db->query("UPDATE ezcobj_state_language
                         SET language_id = $primaryLanguageID
                         WHERE language_id = $engLanguageID");

            // ezcontentclass_name
            $updateSql = "UPDATE ezcontentclass_name
SET
language_locale='$primaryLanguageLocaleCode'
WHERE
language_locale='eng-GB'";
            $this->db->query($updateSql);

            // use high-level api, because it's impossible to update serialized names with direct sqls.
            // use direct access to 'NameList' to avoid unnecessary sql-requests and because
            // we do 'replacement' of existing language(with some 'id') with another language code.
            $contentClassList = eZContentClass::fetchList();
            /** @var eZContentClass $contentClass */
            foreach ($contentClassList as $contentClass) {
                /** @var eZContentClassAttribute[] $classAttributes */
                $classAttributes = $contentClass->fetchAttributes();
                foreach ($classAttributes as $classAttribute) {
                    $classAttribute->NameList->setName($classAttribute->NameList->name('eng-GB'), $primaryLanguageLocaleCode);
                    $classAttribute->NameList->setAlwaysAvailableLanguage($primaryLanguageLocaleCode);
                    $classAttribute->NameList->removeName('eng-GB');
                    $classAttribute->store();
                }

                $contentClass->NameList->setName($contentClass->NameList->name('eng-GB'), $primaryLanguageLocaleCode);
                $contentClass->NameList->setAlwaysAvailableLanguage($primaryLanguageLocaleCode);
                $contentClass->NameList->removeName('eng-GB');
                $contentClass->NameList->setHasDirtyData(false); // to not update 'ezcontentclass_name', because we've already updated it.
                $contentClass->store();
            }

        }

        $allLanguages = [];
        foreach (array_unique($extraLanguageCodes) as $extraLanguageCode) {
            $allLanguages[] = eZLocale::create($extraLanguageCode);
            $allLanguageCodes[] = $extraLanguageCode;
        }
        // Setup all languages
        foreach ($allLanguages as $languageObject) {
            $languageObj = eZContentLanguage::fetchByLocale($languageObject->localeCode());
            // Add it if it is missing (most likely)
            if (!$languageObj) {
                $this->logger->log("Add content language " . $languageObject->localeCode());
                eZContentLanguage::addLanguage($languageObject->localeCode(), $languageObject->internationalLanguageName());
            }
        }
        eZContentLanguage::expireCache();

        // Make sure priority list is changed to the new chosen languages
        $prioritizedLanguages = array_merge(array($primaryLanguageLocaleCode), $allLanguageCodes);
        eZContentLanguage::setPrioritizedLanguages($prioritizedLanguages);
    }

    private function expiryPassword()
    {
        $this->logger->log("Set all passwords expired");
        $updateSql = "UPDATE ezx_mbpaex SET password_last_updated = -1, passwordlifetime = 365";
        $this->db->query($updateSql);
    }
}