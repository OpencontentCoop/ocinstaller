<?php

namespace Opencontent\Installer;

use eZDBInterface;
use Psr\Log\LoggerInterface;

interface InterfaceStepInstaller
{
    public function dryRun(): void;

    public function install(): void;

    public function sync(): void;

    public function getLogger(): LoggerInterface;
    
    public function setLogger(LoggerInterface $logger): void;

    public function getInstallerVars(): InstallerVars;
    
    public function setInstallerVars(InstallerVars $installerVars): void;

    public function getIoTools(): IOTools;
    
    public function setIoTools(IOTools $ioTools): void;

    public function getStep();

    public function setStep($step);

    public function getDb(): eZDBInterface;

    public function setDb(eZDBInterface $db): void;
}