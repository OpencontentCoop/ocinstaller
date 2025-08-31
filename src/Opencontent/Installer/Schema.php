<?php

namespace Opencontent\Installer;

use Exception;
use eZContentClass;
use eZContentLanguage;
use eZDbSchema;
use eZExtension;
use eZINI;
use eZLocale;

class Schema extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $cleanDb;

    private $installBaseSchema;

    private $installExtensionsSchema;

    private $primaryLanguageCode;

    private $extraLanguageCodes;

    private $cleanDataDirectory;

    private $activeExtensions;

    private $installDfsSchema;

    public function __construct($cleanDb, $installBaseSchema, $installExtensionsSchema, $languageList, $cleanDataDirectory, $installDfsSchema)
    {
        $this->cleanDb = $cleanDb;
        $this->installBaseSchema = $installBaseSchema;
        $this->installExtensionsSchema = $installExtensionsSchema;
        $this->primaryLanguageCode = array_shift($languageList);
        $this->extraLanguageCodes = $languageList;
        $this->installDfsSchema = $installDfsSchema;

        $cleanDataDirectoryDefault = 'vendor/opencontent/ocinstaller/cleandata';
        $this->cleanDataDirectory = $cleanDataDirectory ? $cleanDataDirectory : $cleanDataDirectoryDefault;
        $this->activeExtensions = eZExtension::activeExtensions();

        $ini = eZINI::instance('dbschema.ini');
        $schemaPaths = $ini->variable('SchemaSettings', 'SchemaPaths');
        $schemaPaths['postgresql'] = __DIR__ . '/ezpgsqlschema.php';
        $ini->setVariable('SchemaSettings', 'SchemaPaths', $schemaPaths);
    }

    public function dryRun(): void
    {
        if ($this->cleanDb) {
            $this->logger->info('Cleanup db');
        }

        $baseSchema = $this->cleanDataDirectory . '/db_schema.dba';
        $baseData = $this->cleanDataDirectory . '/db_data.dba'; //admin change_password
        $dfsSchema = $this->cleanDataDirectory . '/db_dfs_schema.dba';

        if ($this->installBaseSchema) {
            $this->logger->info("Install schema " . $baseSchema);
            try {
                $this->db->query('select id from ezcontentobject limit 1');
                $this->installerVars['schema_already_exists'] = true;
                $this->installerVars['is_install_from_scratch'] = false;
            } catch (\eZDBException $e) {
                $this->installerVars['schema_already_exists'] = false;
                $this->installerVars['is_install_from_scratch'] = true;
            }
        }

        if ($this->installDfsSchema) {
            $this->logger->info("Install schema " . $dfsSchema);
        }

        if ($this->installBaseSchema) {
            $this->logger->info("Install schema " . $baseData);
        }

        if ($this->installBaseSchema) {
            $activeExtensions = ['ezmbpaex'];
            if ($this->installExtensionsSchema) {
                $activeExtensions = array_merge(
                    $activeExtensions,
                    $this->activeExtensions
                );
            }
            $extensionsDir = eZExtension::baseDirectory();
            foreach (array_unique($activeExtensions) as $activeExtension) {
                $extensionSchema = $extensionsDir . '/' . $activeExtension . '/share/db_schema.dba';
                if (file_exists($extensionSchema)) {
                    $this->logger->info("Install schema " . $extensionSchema);
                }
            }
        }
    }

    public function install(): void
    {
        if ($this->cleanDb) {
            $this->cleanup();
        }

        if ($this->installBaseSchema) {

            $baseSchema = $this->cleanDataDirectory . '/db_schema.dba';
            $baseData = $this->cleanDataDirectory . '/db_data.dba'; //admin change_password
            $dfsSchema = $this->cleanDataDirectory . '/db_dfs_schema.dba';

            $this->installSchemaAndData($baseSchema, $baseData, $dfsSchema);

            $activeExtensions = ['ezmbpaex'];
            if ($this->installExtensionsSchema) {
                $activeExtensions = array_merge(
                    $activeExtensions,
                    $this->activeExtensions
                );
            }
            $this->installExtensionsSchema($activeExtensions);

            $this->setLanguages($this->primaryLanguageCode, $this->extraLanguageCodes);
        }

    }

    private function cleanup()
    {
        $this->logger->info('Cleanup db');
        \eZDB::instance()->query('DROP MATERIALIZED VIEW IF EXISTS ocinstall_tags');
        \eZDB::instance()->query('DROP MATERIALIZED VIEW IF EXISTS ocinstall_tags_tree');
        \eZDB::instance()->query('DROP MATERIALIZED VIEW IF EXISTS ocbooking');
        $relationTypes = $this->db->supportedRelationTypes();
        $result = true;
        $matchRegexp = "#^ez|^sql|^oc|^openpa|^cjw|tmp_notification_rule_s#";
        foreach ($relationTypes as $relationType) {
            $relationItems = $this->db->relationList($relationType);
            foreach ($relationItems as $relationItem) {
                if ($matchRegexp !== false and
                    !preg_match($matchRegexp, $relationItem))
                    continue;

                if (!$this->db->removeRelation($relationItem . ' CASCADE', $relationType)) {
                    $result = false;
                    break;
                }
            }
            if (!$result)
                break;
        }

        return $result;
    }

    // idempotente!
    private function installSchemaExtras()
    {
        $this->getLogger()->info("Install schema extras");
        // alter_contentobject_date_interger_type.sql
        $sql = 'DO $do$ BEGIN IF 
                (SELECT data_type FROM information_schema.columns WHERE column_name = \'published\' 
                AND table_name = \'ezcontentobject\') = \'integer\' 
            THEN 
                ALTER TABLE ezcontentobject ALTER COLUMN published TYPE BIGINT;
                ALTER TABLE ezcontentobject ALTER COLUMN modified TYPE BIGINT; 
            END IF; 
        END $do$';
        $this->db->query($sql);
    }

    private function installSchemaAndData($baseSchema, $baseData, $dfsSchema)
    {
        $this->logger->info("Install schema " . $baseSchema);
        $schemaArray = eZDbSchema::read($baseSchema, true);
        $schemaArray['type'] = strtolower($this->db->databaseName());
        $schemaArray['instance'] = $this->db;
        $dbSchema = eZDbSchema::instance($schemaArray);
        $params = array(
            'schema' => true,
            'data' => false
        );

        try {
            if (!$dbSchema->insertSchema($params)) {
                throw new Exception("Unknown error");
            }
            $this->installerVars['schema_already_exists'] = false;
            $this->installerVars['is_install_from_scratch'] = true;
        } catch (\eZDBException $e) {
            $this->db->rollback();
            if (strpos($e->getMessage(), 'already exists') !== false) {
                $this->getLogger()->error(' -> already installed');
                $this->installerVars['schema_already_exists'] = true;
                $this->installerVars['is_install_from_scratch'] = false;
            } else {
                throw $e;
            }
        }
        $this->installSchemaExtras();

        $dfsSchemaArray = [];
        if ($this->installDfsSchema) {
            $this->logger->info("Install schema " . $dfsSchema);
            $dfsSchemaArray = eZDbSchema::read($dfsSchema, true);
            $dfsSchemaArray['type'] = strtolower($this->db->databaseName());
            $dfsSchemaArray['instance'] = $this->db;
            $dbDfsSchema = eZDbSchema::instance($dfsSchemaArray);
            $params = array(
                'schema' => true,
                'data' => false
            );
            try {
                if (!$dbDfsSchema->insertSchema($params)) {
                    throw new Exception("Unknown error");
                }
            } catch (\eZDBException $e) {
                $this->db->rollback();
                if (strpos($e->getMessage(), 'already exists') !== false) {
                    $this->getLogger()->error(' -> already installed');
                } else {
                    throw $e;
                }
            }
        }

        $this->logger->info("Install schema " . $baseData);
        $dataArray = eZDbSchema::read($baseData, true);
        $dataArray['type'] = strtolower($this->db->databaseName());
        $dataArray['instance'] = $this->db;
        $dbDataSchema = eZDbSchema::instance($dataArray);
        $params = array(
            'schema' => false,
            'data' => true
        );
        try {
            if (!$dbDataSchema->insertSchema($params)) {
                throw new Exception("Unknown error");
            }
        } catch (\eZDBException $e) {
            $this->db->rollback();
            if (strpos($e->getMessage(), 'already exists') !== false) {
                $this->getLogger()->error(' -> already installed');
            } else {
                throw $e;
            }
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

                $this->logger->info("Install schema " . $extensionSchema);

                $extensionSchemaArray = eZDbSchema::read($extensionSchema, true);
                $extensionSchemaArray['type'] = strtolower($this->db->databaseName());
                $extensionSchemaArray['instance'] = $this->db;

                $dbSchema = eZDbSchema::instance($extensionSchemaArray);
                $params = array(
                    'schema' => true,
                    'data' => true
                );
                try {
                    if (!$dbSchema->insertSchema($params)) {
                        throw new Exception("Unknown error");
                    }
                } catch (\eZDBException $e) {
                    $this->db->rollback();
                    if (strpos($e->getMessage(), 'already exists') !== false) {
                        $this->getLogger()->error(' -> already installed');
                    } else {
                        throw $e;
                    }
                }
            }

            if ($activeExtension == 'ocmultibinary') {
                $this->db->query("ALTER TABLE ezbinaryfile DROP CONSTRAINT ezbinaryfile_pkey;");
                $this->db->query("ALTER TABLE ONLY ezbinaryfile ADD CONSTRAINT ezbinaryfile_pkey PRIMARY KEY (contentobject_attribute_id , version, filename );");
            }
        }
    }

    private function setLanguages($primaryLanguageCode, $extraLanguageCodes = [])
    {
        $installerDataLocale = 'ita-IT';

        $primaryLanguage = eZLocale::create($primaryLanguageCode);
        $primaryLanguageLocaleCode = $primaryLanguage->localeCode();
        $primaryLanguageName = $primaryLanguage->languageName();

        // Make sure objects use the selected main language instead of $installerDataLocale
        if ($primaryLanguageLocaleCode != $installerDataLocale) {
            $this->logger->info("Set primary content language " . $primaryLanguageLocaleCode);

            $extraLanguageCodes[] = $installerDataLocale;

            $engLanguageObj = eZContentLanguage::fetchByLocale($installerDataLocale);
            if (!$engLanguageObj) {
                $engLanguage = eZLocale::create($installerDataLocale);
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

            // Updates databases that have $installerDataLocale data to the new locale.
            $updateSql = "UPDATE ezcontentobject_name
SET
content_translation='$primaryLanguageLocaleCode',
real_translation='$primaryLanguageLocaleCode',
language_id=$primaryLanguageID
WHERE
content_translation='$installerDataLocale' OR
real_translation='$installerDataLocale'";
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
language_code='$installerDataLocale'";
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
language_locale='$installerDataLocale'";
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
                    $classAttribute->NameList->setName($classAttribute->NameList->name($installerDataLocale), $primaryLanguageLocaleCode);
                    $classAttribute->NameList->setAlwaysAvailableLanguage($primaryLanguageLocaleCode);
                    $classAttribute->NameList->removeName($installerDataLocale);
                    $classAttribute->store();
                }

                $contentClass->NameList->setName($contentClass->NameList->name($installerDataLocale), $primaryLanguageLocaleCode);
                $contentClass->NameList->setAlwaysAvailableLanguage($primaryLanguageLocaleCode);
                $contentClass->NameList->removeName($installerDataLocale);
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
                $this->logger->info("Add content language " . $languageObject->localeCode());
                eZContentLanguage::addLanguage($languageObject->localeCode(), $languageObject->internationalLanguageName());
            }
        }
        eZContentLanguage::expireCache();

        // Make sure priority list is changed to the new chosen languages
        $prioritizedLanguages = array_merge(array($primaryLanguageLocaleCode), $allLanguageCodes);
        eZContentLanguage::setPrioritizedLanguages($prioritizedLanguages);
    }

    public function expiryPassword()
    {
        $this->logger->info("Set all passwords expired");
        $updateSql = "UPDATE ezx_mbpaex SET password_last_updated = -1, passwordlifetime = 365";
        $this->db->query($updateSql);
        if (!defined('\eZUser::PASSWORD_HASH_PHP_DEFAULT')) {
            $user = \eZUser::fetch(14);
            if ($user instanceof \eZUser) {
                $newHash = $user->createHash($user->attribute('login'), 'change_password', \eZUser::site(), \eZUser::hashType());
                $user->setAttribute('password_hash', $newHash);
                $user->setAttribute('password_hash_type', \eZUser::hashType());
                $user->store();
                $this->logger->info("Set legacy hash type to admin user");
            }
        }

    }
}