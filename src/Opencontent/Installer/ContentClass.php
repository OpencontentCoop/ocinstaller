<?php

namespace Opencontent\Installer;

use OCClassTools;
use OCOpenDataClassRepositoryCache;

class ContentClass extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $identifier;

    public function install()
    {
        $this->identifier = $this->step['identifier'];
        $sourcePath = "classes/{$this->identifier}.yml";
        $definitionJsonFile = $this->createJsonFile($sourcePath);

        $this->logger->info("Install class $this->identifier");
        $tools = new OCClassTools($this->identifier, true, array(), $definitionJsonFile);
        $tools->sync();

        $class = $tools->getLocale();
        $this->installerVars['class_' . $this->identifier] = $class->attribute('id');

        OCOpenDataClassRepositoryCache::clearCache();

        @unlink($definitionJsonFile);
    }

    private function createJsonFile($source)
    {
        $data = $this->ioTools->getJsonContents($source);
        $data = $this->hydrateData($data);

        if ($data) {
            $filePath = $this->ioTools->getFile($source);
            $destinationFilePath = substr($filePath, 0, -4) . '.json';
            \eZFile::create(basename($destinationFilePath), dirname($destinationFilePath), json_encode($data));

            return $destinationFilePath;
        }

        return false;
    }

    private function hydrateData($data)
    {
        $hydrateData = [];
        foreach (\Opencontent\Installer\Dumper\ContentClass::$properties as $source => $target){
            if (isset($data[$source])){
                $value = $data[$source];
                if (strpos($source, 'serialized_') !== false){
                    $value = serialize($value);
                }
                $hydrateData[$target] = $value;
            }
        }

        $DataMap = [];
        foreach ($data['data_map'] as $identifier => $values){
            $DataMap[$identifier] = $this->hydrateField($values);
        }
        $hydrateData['DataMap'] = [$DataMap];

        $hydrateData['InGroups'] = [];
        foreach ($data['groups'] as $name){
            $hydrateData['InGroups'][] = ['GroupName' => $name];
        }


        return $hydrateData;
    }

    private function hydrateField($data)
    {
        $hydrateData = [];
        foreach (\Opencontent\Installer\Dumper\ContentClass::$fields as $source => $target){
            if (isset($data[$source])){
                $value = $data[$source];
                if (strpos($source, 'serialized_') !== false){
                    $value = serialize($value);
                }
                $hydrateData[$target] = $value;
            }
        }

        return $hydrateData;
    }
}