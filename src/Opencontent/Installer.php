<?php

use Symfony\Component\Yaml\Yaml;
use Opencontent\Opendata\Api\ContentRepository;
use Opencontent\Opendata\Api\EnvironmentLoader;

class OpenContentInstaller
{
    protected $db;

    protected $dataDir;

    protected $installerData = array();
    protected $installerVars = array();

    /**
     * OpenContentInstaller constructor.
     * @param eZDBInterface $db
     * @param $dataDir
     * @throws Exception
     */
    public function __construct(eZDBInterface $db, $dataDir)
    {
        $this->db = $db;
        $this->dataDir = rtrim($dataDir, '/');
        $this->validateData();
        $this->installerData = Yaml::parse(file_get_contents($this->dataDir . '/installer.yml'));
        $this->log("Install " . $this->installerData['name'] . ' version ' . $this->installerData['version']);
    }

    protected function log($message)
    {
        eZCLI::instance()->output($message);
    }

    final public function cleanup()
    {
        $this->log('Cleanup db');
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

    final public function installSchemaAndData($baseSchema, $baseData)
    {
        $this->log("Install schema " . $baseSchema);
        $schemaArray = eZDbSchema::read($baseSchema, true);

        $this->log("Install schema " . $baseData);
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

    final public function installExtensionsSchema(array $activeExtensions = [], array $excludeExtensionList = [])
    {
        $extensionsDir = eZExtension::baseDirectory();
        foreach (array_unique($activeExtensions) as $activeExtension) {
            if (in_array($activeExtension, $excludeExtensionList)) {
                continue;
            }
            $extensionSchema = $extensionsDir . '/' . $activeExtension . '/share/db_schema.dba';

            if (file_exists($extensionSchema)) {

                $this->log("Install schema " . $extensionSchema);

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

    final public function setLanguages($primaryLanguageCode, $extraLanguageCodes = [])
    {
        $primaryLanguage = eZLocale::create($primaryLanguageCode);
        $primaryLanguageLocaleCode = $primaryLanguage->localeCode();
        $primaryLanguageName = $primaryLanguage->languageName();

        // Make sure objects use the selected main language instead of eng-GB
        if ($primaryLanguageLocaleCode != 'eng-GB') {
            $this->log("Set primary content language " . $primaryLanguageLocaleCode);

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
                $this->log("Add content language " . $languageObject->localeCode());
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
        $this->log("Set all passwords expired");
        $updateSql = "UPDATE ezx_mbpaex SET password_last_updated = -1, passwordlifetime = 365";
        $this->db->query($updateSql);
    }

    protected function validateData()
    {
        if (!file_exists($this->dataDir . '/installer.yml')) {
            throw new Exception("File {$this->dataDir}/installer.yml not found");
        }
    }

    public function install()
    {
        /** @var eZUser $adminUser */
        $adminUser = eZUser::fetchByName('admin');
        eZUser::setCurrentlyLoggedInUser($adminUser, $adminUser->id());

        $this->loadDataVariables();

        foreach ($this->installerData['steps'] as $step) {
            switch ($step['type']) {
                case 'tagtree':
                    $this->installTagTree($step);
                    break;

                case 'state':
                    $this->installState($step);
                    break;

                case 'section':
                    $this->installSection($step);
                    break;

                case 'class':
                    $this->installClass($step);
                    break;

                case 'content':
                    $this->installContent($step);
                    break;

                case 'role':
                    $this->installRole($step);
                    break;

                default:
                    throw new Exception("Step type " . $step['type'] . ' not handled');
            }
        }
    }

    protected function loadDataVariables()
    {
        if (isset($this->installerData['variables'])) {
            $this->log("Load installer vars:");

            foreach ($this->installerData['variables'] as $variable) {
                $name = $variable['name'];
                $value = $this->parseVarValue($variable['value']);
                $this->log(" - $name: $value");
                $this->installerVars[$name] = $value;
            }
        }

        $stepsData = $this->filterVars(json_encode($this->installerData['steps']));
        $this->installerData['steps'] = json_decode($stepsData, true);
    }

    protected function filterVars($data)
    {
        foreach ($this->installerVars as $name => $value) {
            $data = str_replace('$' . $name, $value, $data);
        }

        return $data;
    }

    protected function parseVarValue($value)
    {
        if (strpos($value, 'env(') !== false) {
            $envVariable = substr($value, 4, -1);
            $value = $_ENV[$envVariable];
        }

        if (strpos($value, 'ini(') !== false) {
            $iniVariable = substr($value, 4, -1);
            list($group, $variable, $file) = explode(',', $iniVariable);
            $value = eZINI::instance($file)->variable($group, $variable);
        }

        return $value;
    }

    protected function getDataFile($source)
    {
        $filePath = eZSys::rootDir() . '/' . $this->dataDir . '/' . $source;

        if (file_exists($filePath)) {

            return $filePath;
        }

        return false;
    }

    protected function getJsonData($source)
    {
        $filePath = $this->getDataFile($source);

        if (file_exists($filePath)) {
            $data = file_get_contents($filePath);
            foreach ($this->installerVars as $name => $value) {
                $data = str_replace('$' . $name, $value, $data);
            }
            return Yaml::parse($data);
        }

        return false;
    }

    protected function createJsonDataFile($source)
    {
        $data = $this->getJsonData($source);

        if ($data) {
            $filePath = $this->getDataFile($source);
            $destinationFilePath = substr($filePath, 0, -4) . '.json';
            eZFile::create(basename($destinationFilePath), dirname($destinationFilePath), json_encode($data));

            return $destinationFilePath;
        }

        return false;
    }

    protected function removeJsonDataFile($source)
    {
        $filePath = eZSys::rootDir() . '/' . $this->dataDir . '/' . $source;
        $destinationFilePath = substr($filePath, 0, -4) . '.json';
        @unlink($destinationFilePath);
    }

    protected function installTagTree($step)
    {
        $tagTreeInstaller = new OpenContentTagTreeInstaller($step['source']);
        $this->log("Import tag tree " . basename($step['source']));
        $tagTreeInstaller->import();
    }

    protected function installState($step)
    {
        $identifier = $step['identifier'];
        $stateDefinition = $this->getJsonData("states/{$identifier}.yml");

        $groupIdentifier = $stateDefinition['group_identifier'];
        $groupNames = $stateDefinition['group_name'];
        $states = $stateDefinition['states'];

        $this->log("Create state group " . $stateDefinition['group_identifier']);

        $stateGroup = eZContentObjectStateGroup::fetchByIdentifier($groupIdentifier);
        if (!$stateGroup instanceof eZContentObjectStateGroup) {
            $stateGroup = new eZContentObjectStateGroup();
            $stateGroup->setAttribute('identifier', $groupIdentifier);
            $stateGroup->setAttribute('default_language_id', 2);

            /** @var eZContentObjectStateLanguage[] $translations */
            $translations = $stateGroup->allTranslations();
            foreach ($translations as $translation) {
                /** @var eZContentLanguage $language */
                $language = eZContentLanguage::fetch($translation->attribute('real_language_id'));
                if (isset($groupNames[$language->attribute('locale')])) {
                    $translation->setAttribute('name', $groupNames[$language->attribute('locale')]);
                    $translation->setAttribute('description', $groupNames[$language->attribute('locale')]);
                } else {
                    $translation->setAttribute('name', $groupNames['eng-GB']);
                    $translation->setAttribute('description', $groupNames['eng-GB']);
                }
            }

            $messages = array();
            $isValid = $stateGroup->isValid($messages);
            if (!$isValid) {
                throw new Exception(implode(',', $messages));
            }
            $stateGroup->store();
        }

        foreach ($states as $state) {
            $stateObject = $stateGroup->stateByIdentifier($state['identifier']);
            if (!$stateObject instanceof eZContentObjectState) {
                $stateObject = $stateGroup->newState($state['identifier']);
                $stateObject->setAttribute('default_language_id', 2);

                /** @var eZContentObjectStateLanguage[] $stateTranslations */
                $stateTranslations = $stateObject->allTranslations();

                foreach ($stateTranslations as $translation) {
                    $language = eZContentLanguage::fetch($translation->attribute('language_id'));
                    if (isset($state['name'][$language->attribute('locale')])) {
                        $translation->setAttribute('name', $state['name'][$language->attribute('locale')]);
                        $translation->setAttribute('description', $state['name'][$language->attribute('locale')]);
                    } else {
                        $translation->setAttribute('name', $state['name']['eng-GB']);
                        $translation->setAttribute('description', $state['name']['eng-GB']);
                    }
                }
                $messages = array();
                $isValid = $stateObject->isValid($messages);
                if (!$isValid) {
                    throw new Exception(implode(',', $messages));
                }
                $stateObject->store();
            }
        }
    }

    protected function installSection($step)
    {
        $identifier = $step['identifier'];
        $sectionDefinition = $this->getJsonData("sections/{$identifier}.yml");

        $name = $sectionDefinition['name'];
        $identifier = $sectionDefinition['identifier'];
        $navigationPart = $sectionDefinition['navigation_part'];

        $this->log("Create section " . $identifier);

        $section = eZSection::fetchByIdentifier($identifier, false);
        if (isset($section['id'])) {
            $section = eZSection::fetch($section['id']);
        }
        if (!$section instanceof eZSection) {
            $section = new eZSection(array());
            $section->setAttribute('name', $name);
            $section->setAttribute('identifier', $identifier);
            $section->setAttribute('navigation_part_identifier', $navigationPart);
            $section->store();
        }
        if (!$section instanceof eZSection) {
            throw new Exception("Section $identifier not found");
        }
    }

    protected function installClass($step)
    {
        $identifier = $step['identifier'];
        if (isset($step['source'])) {
            $parts = explode('/', $step['source']);
            array_pop($parts);
            $source = implode('/', $parts) . '/';
        } else {
            $source = $this->createJsonDataFile("classes/{$identifier}.yml");
        }

        $this->log("Create class $identifier");
        $tools = new OCClassTools($identifier, true, array(), $source);
        $tools->sync();

        $this->removeJsonDataFile("classes/{$identifier}.yml");
        OCOpenDataClassRepositoryCache::clearCache();
    }

    protected function installContent($step)
    {
        $identifier = $step['identifier'];
        $content = $this->getJsonData("contents/{$identifier}.yml");

        $this->log("Create content " . $identifier);

        $contentRepository = new ContentRepository();
        $contentRepository->setEnvironment(EnvironmentLoader::loadPreset('content'));
        $result = $contentRepository->create($content);

        $id = $result['content']['metadata']['id'];
        $nodeId = $result['content']['metadata']['mainNodeId'];
        $this->installerVars['content_' . $identifier . '_node'] = $nodeId;
        $this->installerVars['content_' . $identifier . '_object'] = $id;

        if (isset($step['swap_with'])){
            $source = $nodeId;
            $target = $step['swap_with'];
            eZContentOperationCollection::swapNode($source, $target, array($source, $target));
            $this->installerVars['content_' . $identifier . '_node'] = $target;
            if (isset($step['remove_swapped']) && $step['remove_swapped']){
                eZContentOperationCollection::deleteObject(array($nodeId));
            }
        }
    }

    protected function installRole($step)
    {

    }
}