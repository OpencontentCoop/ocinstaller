<?php

namespace Opencontent\Installer;

abstract class ChainStepInstaller extends AbstractStepInstaller implements InterfaceStepInstaller
{
    /**
     * @var InterfaceStepInstaller[]
     */
    protected $stepInstallers = [];

    public function dryRun()
    {
        foreach ($this->stepInstallers as $stepInstaller){
            $stepInstaller->dryRun();
        }
    }

    public function install()
    {
        foreach ($this->stepInstallers as $stepInstaller){
            $stepInstaller->install();
        }
    }

    public function setStep($step)
    {
        foreach ($this->stepInstallers as $stepInstaller){
            $stepInstaller->setStep($step);
        }
        parent::setStep($step);
    }

    public function setInstallerVars($installerVars)
    {
        foreach ($this->stepInstallers as $stepInstaller){
            $stepInstaller->setInstallerVars($installerVars);
        }
        parent::setInstallerVars($installerVars);
    }

    public function setDb($db)
    {
        foreach ($this->stepInstallers as $stepInstaller){
            $stepInstaller->setDb($db);
        }
        parent::setDb($db);
    }

    public function setIoTools($ioTools)
    {
        foreach ($this->stepInstallers as $stepInstaller){
            $stepInstaller->setIoTools($ioTools);
        }
        parent::setIoTools($ioTools);
    }

    public function setLogger($logger)
    {
        foreach ($this->stepInstallers as $stepInstaller){
            $stepInstaller->setLogger($logger);
        }
        parent::setLogger($logger);
    }
}