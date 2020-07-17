<?php

namespace Opencontent\Installer;

use OCClassTools;
use OCOpenDataClassRepositoryCache;
use Opencontent\Installer\Serializer\ContentClassSerializer;

class ContentClass extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $identifier;

    public function dryRun()
    {
        $identifier = $this->step['identifier'];
        $this->logger->info("Install class $identifier");
        $this->installerVars['class_' . $identifier] = 0;
    }

    public function install()
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

        $tools = new OCClassTools($definitionData['identifier'], true, array(), $definitionJsonFile);

        $tools->compare();
        $this->logCompare($tools->getData());

        $tools->sync($force, $removeExtras);

        $class = $tools->getLocale();
        $this->installerVars['class_' . $this->identifier] = $class->attribute('id');

        OCOpenDataClassRepositoryCache::clearCache();

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

            if (count($errors) > 0)
                $this->logger->debug('    Attributi che differiscono dal prototipo: ' . count($result->diffAttributes));
            elseif (count($warnings) > 0)
                $this->logger->debug('    Attributi che differiscono dal prototipo: ' . count($result->diffAttributes));
            else
                $this->logger->debug('    Attributi che differiscono dal prototipo: ' . count($result->diffAttributes));

            foreach ($result->diffAttributes as $identifier => $value) {
                if (isset($result->errors[$identifier]))
                    $this->logger->debug("     -> $identifier");
                elseif (isset($result->warnings[$identifier]))
                    $this->logger->debug("     -> $identifier");
                else
                    $this->logger->debug("     -> $identifier");

                foreach ($value as $diff) {
                    if (isset($result->errors[$identifier][$diff['field_name']]))
                        $this->logger->debug("        {$diff['field_name']}");
                    elseif (isset($result->warnings[$identifier][$diff['field_name']]))
                        $this->logger->debug("        {$diff['field_name']}");
                    else
                        $this->logger->debug("        {$diff['field_name']}");
                }
            }
        }
        if ($result->hasDiffProperties) {
            if (isset($result->errors['properties']))
                $this->logger->debug('    Proprietà che differiscono dal prototipo: ' . count($result->diffProperties));
            elseif (isset($result->warnings['properties']))
                $this->logger->debug('    Proprietà che differiscono dal prototipo: ' . count($result->diffProperties));
            else
                $this->logger->debug('    Proprietà che differiscono dal prototipo: ' . count($result->diffProperties));

            foreach ($result->diffProperties as $property) {
                if (isset($result->errors['properties'][$property['field_name']]))
                    $this->logger->debug("        {$property['field_name']}");
                elseif (isset($result->warnings['properties'][$property['field_name']]))
                    $this->logger->debug("        {$property['field_name']}");
                else
                    $this->logger->debug("        {$property['field_name']}");
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
}