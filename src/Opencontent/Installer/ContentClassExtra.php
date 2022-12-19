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

        try {
            $class = eZContentClass::fetchByIdentifier($identifier);
            $data = OCClassExtraParametersManager::instance($class)->getAllParameters();
            $dataYaml = Yaml::dump($data, 10);
            Tool::createFile(
                $this->ioTools->getDataDir(),
                'classextra',
                $identifier . '.yml',
                $dataYaml
            );
        }catch (\Exception $e){
            $this->getLogger()->error($e->getMessage());
        }
    }
}