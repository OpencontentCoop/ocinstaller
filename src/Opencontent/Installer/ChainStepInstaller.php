<?php

namespace Opencontent\Installer;

use eZDBInterface;
use Psr\Log\LoggerInterface;

abstract class ChainStepInstaller extends AbstractStepInstaller implements InterfaceStepInstaller
{
    /**
     * @var InterfaceStepInstaller[]
     */
    protected $stepInstallers = [];

    public function dryRun(): void
    {
        foreach ($this->stepInstallers as $stepInstaller){
            $stepInstaller->dryRun();
        }
    }

    public function install(): void
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

    public function setInstallerVars(InstallerVars $installerVars): void
    {
        foreach ($this->stepInstallers as $stepInstaller){
            $stepInstaller->setInstallerVars($installerVars);
        }
        parent::setInstallerVars($installerVars);
    }

    public function setDb(eZDBInterface $db): void
    {
        foreach ($this->stepInstallers as $stepInstaller){
            $stepInstaller->setDb($db);
        }
        parent::setDb($db);
    }

    public function setIoTools(IOTools $ioTools): void
    {
        foreach ($this->stepInstallers as $stepInstaller){
            $stepInstaller->setIoTools($ioTools);
        }
        parent::setIoTools($ioTools);
    }

    public function setLogger(LoggerInterface $logger): void
    {
        foreach ($this->stepInstallers as $stepInstaller){
            $stepInstaller->setLogger($logger);
        }
        parent::setLogger($logger);
    }

    public function sync(): void
    {
        foreach ($this->stepInstallers as $stepInstaller){
            $stepInstaller->sync();
        }
    }
}