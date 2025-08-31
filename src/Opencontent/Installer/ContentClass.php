<?php

namespace Opencontent\Installer;

use OCClassTools;
use OCOpenDataClassRepositoryCache;
use Opencontent\Installer\Serializer\ContentClassSerializer;
use Symfony\Component\Yaml\Yaml;

class ContentClass extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $identifier;

    public function dryRun(): void
    {
        $identifier = $this->step['identifier'];
        $this->logger->info("Install class $identifier");

        $sourcePath = "classes/{$identifier}.yml";
        $definitionData = $this->ioTools->getJsonContents($sourcePath);
        $definitionJsonFile = $this->createJsonFile($sourcePath);
        try {
            $tools = new OCClassTools($definitionData['identifier'], false, [], $definitionJsonFile);
            $tools->compare();
            $this->logCompare($tools->getData());
        } catch (\Exception $e) {
            $this->logger->warning('    ' . $e->getMessage());
        }

        $this->installerVars['class_' . $identifier] = 0;
    }

    public function install(): void
    {
        $this->identifier = $this->step['identifier'];
        $sourcePath = "classes/{$this->identifier}.yml";
        $definitionData = $this->ioTools->getJsonContents($sourcePath);
        $definitionJsonFile = $this->createJsonFile($sourcePath);

        $this->logger->info("Install class $this->identifier");
        $force = isset($this->step['force']) && $this->step['force'];
        if ($force) {
            $this->logger->info(' - forcing sync');
        }
        $removeExtras = isset($this->step['remove_extra']) && $this->step['remove_extra'];
        if ($removeExtras) {
            $this->logger->info(' - removing extra attributes');
        }

        $tools = new OCClassTools($definitionData['identifier'], true, [], $definitionJsonFile);

        $tools->compare();
        $this->logCompare($tools->getData());

        $tools->sync($force, $removeExtras);

        $class = $tools->getLocale();
        $this->installerVars['class_' . $this->identifier] = $class->attribute('id');

        $repository = new \Opencontent\Opendata\Api\ClassRepository();
        $repository->clearCache($this->identifier);

        $handler = \eZExpiryHandler::instance();
        $handler->setTimestamp('class-identifier-cache', -1);

        @unlink($definitionJsonFile);
    }

    private function logCompare($result)
    {
        if ($result->missingAttributes) {
            $this->logger->debug('    Attributi mancanti rispetto al prototipo: ' . count($result->missingAttributes));
            foreach ($result->missingAttributes as $identifier => $original) {
                $this->logger->debug("     -> $identifier ({$original->DataTypeString})");
            }
        }
        if ($result->extraAttributes) {
            $this->logger->debug('    Attributi aggiuntivi rispetto al prototipo: ' . count($result->extraAttributes));
            foreach ($result->extraAttributes as $attribute) {
                $detail = $result->extraDetails[$attribute->Identifier];
                $this->logger->debug("     -> {$attribute->Identifier} ({$attribute->DataTypeString})");
            }
        }
        if ($result->hasDiffAttributes) {
            $identifiers = array_keys($result->diffAttributes);
            $errors = array_intersect(array_keys($result->errors), $identifiers);
            $warnings = array_intersect(array_keys($result->warnings), $identifiers);

            if (count($errors) > 0) {
                $this->logger->debug('    Attributi che differiscono dal prototipo: ' . count($result->diffAttributes));
            } elseif (count($warnings) > 0) {
                $this->logger->debug('    Attributi che differiscono dal prototipo: ' . count($result->diffAttributes));
            } else {
                $this->logger->debug('    Attributi che differiscono dal prototipo: ' . count($result->diffAttributes));
            }

            foreach ($result->diffAttributes as $identifier => $value) {
                if (isset($result->errors[$identifier])) {
                    $this->logger->debug("     -> $identifier");
                } elseif (isset($result->warnings[$identifier])) {
                    $this->logger->debug("     -> $identifier");
                } else {
                    $this->logger->debug("     -> $identifier");
                }

                foreach ($value as $diff) {
                    if (isset($result->errors[$identifier][$diff['field_name']])) {
                        $this->logger->debug("        {$diff['field_name']}");
                    } elseif (isset($result->warnings[$identifier][$diff['field_name']])) {
                        $this->logger->debug("        {$diff['field_name']}");
                    } else {
                        $this->logger->debug("        {$diff['field_name']}");
                    }
                }
            }
        }
        if ($result->hasDiffProperties) {
            if (isset($result->errors['properties'])) {
                $this->logger->debug('    Proprietà che differiscono dal prototipo: ' . count($result->diffProperties));
            } elseif (isset($result->warnings['properties'])) {
                $this->logger->debug('    Proprietà che differiscono dal prototipo: ' . count($result->diffProperties));
            } else {
                $this->logger->debug('    Proprietà che differiscono dal prototipo: ' . count($result->diffProperties));
            }

            foreach ($result->diffProperties as $property) {
                if (isset($result->errors['properties'][$property['field_name']])) {
                    $this->logger->debug("        {$property['field_name']}");
                } elseif (isset($result->warnings['properties'][$property['field_name']])) {
                    $this->logger->debug("        {$property['field_name']}");
                } else {
                    $this->logger->debug("        {$property['field_name']}");
                }
            }
        }
    }

    private function createJsonFile($source)
    {
        $data = $this->ioTools->getJsonContents($source);
        $serializer = new ContentClassSerializer($this->installerVars);
        $data = $serializer->unserialize($data);

        if ($data) {
            $filePath = $this->ioTools->getFile($source);
            $destinationFilePath = substr($filePath, 0, -4) . '.json';
            \eZFile::create(basename($destinationFilePath), dirname($destinationFilePath), json_encode($data));

            return $destinationFilePath;
        }

        return false;
    }

    public function sync(): void
    {
        $this->identifier = $this->step['identifier'];
        $sourcePath = "classes/{$this->identifier}.yml";
        $filePath = $this->ioTools->getFile($sourcePath);
        $definitionData = Yaml::parseFile($filePath);

        $classIdentifier = $definitionData['identifier'] ?? str_replace('_with_related', '', $this->identifier);
        $class = \eZContentClass::fetchByIdentifier($classIdentifier);
        if (!$class instanceof \eZContentClass){
            return;
        }

        if (isset($definitionData['identifier'])) {
            foreach (
                $this->deserialize($class->attribute('serialized_name_list'), new \eZContentClassNameList())
                as $language => $value
            ) {
                if ($definitionData['serialized_name_list'][$language] !== $value) {
                    $definitionData['serialized_name_list'][$language] = $value;
                }
            }
            foreach ($this->deserialize($class->attribute('serialized_description_list')) as $language => $value) {
                if ($definitionData['serialized_description_list'][$language] !== $value) {
                    $definitionData['serialized_description_list'][$language] = $value;
                }
            }
        }
        /** @var \eZContentClassAttribute[] $dataMap */
        $dataMap = $class->dataMap();
        foreach ($dataMap as $identifier => $attribute) {
            if (isset($definitionData['data_map'][$identifier])) {
                foreach ($this->deserialize($attribute->attribute('serialized_name_list')) as $language => $value) {
                    if ($definitionData['data_map'][$identifier]['serialized_name_list'][$language] !== $value) {
                        $definitionData['data_map'][$identifier]['serialized_name_list'][$language] = $value;
                    }
                }
                foreach ($this->deserialize($attribute->attribute('serialized_description_list')) as $language => $value) {
                    if ($definitionData['data_map'][$identifier]['serialized_description_list'][$language] !== $value) {
                        $definitionData['data_map'][$identifier]['serialized_description_list'][$language] = $value;
                    }
                }
            }
        }
        file_put_contents($filePath, Yaml::dump($definitionData, 10));
    }

    private function deserialize($value, $nameList = null)
    {
        $nameList = $nameList ?? new \eZSerializedObjectNameList();
        $nameList->initFromSerializedList($value);
        return $nameList->NameList;
    }

    private static $identifierHash;

    private static function classAttributeIdentifiersHash()
    {
        if (self::$identifierHash === null) {
            $db = \eZDB::instance();
            $dbName = md5($db->DB);

            $cacheDir = \eZSys::cacheDirectory();
            $phpCache = new \eZPHPCreator(
                $cacheDir,
                'classattributeidentifiers_' . $dbName . '.php',
                '',
                ['clustering' => 'classattridentifiers']
            );

            $handler = \eZExpiryHandler::instance();
            $expiryTime = 0;
            if ($handler->hasTimestamp('class-identifier-cache')) {
                $expiryTime = $handler->timestamp('class-identifier-cache');
            }

            if ($phpCache->canRestore($expiryTime)) {
                $var = $phpCache->restore(['identifierHash' => 'identifier_hash']);
                self::$identifierHash = $var['identifierHash'];
            } else {
                // Fetch identifier/id pair from db
                $query = "SELECT ezcontentclass_attribute.id as attribute_id, ezcontentclass_attribute.identifier as attribute_identifier, ezcontentclass.identifier as class_identifier
                          FROM ezcontentclass_attribute, ezcontentclass
                          WHERE ezcontentclass.id=ezcontentclass_attribute.contentclass_id";
                $identifierArray = $db->arrayQuery($query);

                self::$identifierHash = [];
                foreach ($identifierArray as $identifierRow) {
                    $combinedIdentifier = $identifierRow['class_identifier'] . '/' . $identifierRow['attribute_identifier'];
                    self::$identifierHash[$combinedIdentifier] = (int)$identifierRow['attribute_id'];
                }

                // Store identifier list to cache file
                $phpCache->addVariable('identifier_hash', self::$identifierHash);
                $phpCache->store();
            }
        }
        return self::$identifierHash;
    }
}