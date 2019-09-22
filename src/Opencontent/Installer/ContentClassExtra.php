<?php

namespace Opencontent\Installer;


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

        $class = \eZContentClass::fetchByIdentifier($identifier);
        if (!$class instanceof \eZContentClass){
            throw new \Exception("Class $identifier not found", 1);
        }
        $data = $this->ioTools->getJsonContents("classextra/{$identifier}.yml");
        \OCClassExtraParametersManager::instance($class)->sync($data);
    }
}