<?php

namespace Opencontent\Installer;

use Opencontent\Installer\Dumper\Tool;
use Symfony\Component\Yaml\Yaml;
use eZContentClass;
use OCClassExtraParametersManager;

class ContentClassExtra extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun()
    {
        $identifier = $this->step['identifier'];
        $this->logger->info("Install classextra $identifier");
    }

    public function install()
    {
        $identifier = $this->step['identifier'];
        $this->logger->info("Install classextra $identifier");

        $class = eZContentClass::fetchByIdentifier($identifier);
        if (!$class instanceof eZContentClass){
            throw new \Exception("Class $identifier not found", 1);
        }
        $data = $this->ioTools->getJsonContents("classextra/{$identifier}.yml");
        OCClassExtraParametersManager::instance($class)->sync($data);
    }

    public function sync()
    {
        $identifier = $this->step['identifier'];
        $sourcePath = "classextra/{$identifier}.yml";
        $filePath = $this->ioTools->getFile($sourcePath);
        $definitionData = Yaml::parseFile($filePath);

        $class = \eZContentClass::fetchByIdentifier($identifier);
        if (!$class instanceof \eZContentClass){
            return;
        }

        $extra = (new \OpenPAAttributeGroupClassExtraParameters($class))->getParameters();

        if (!empty($extra)) {
            foreach ($extra as $item){
                $key = $item->attribute('key');
                if (strpos($key, '::') !== false){
                    if (isset($definitionData['attribute_group'][$key]['*'])
                        && $definitionData['attribute_group'][$key]['*'] !== $item->attribute('value')){
                        $definitionData['attribute_group'][$key]['*'] = $item->attribute('value');
                    }
                }
            }
        }

        file_put_contents($filePath, Yaml::dump($definitionData, 10));
    }
}