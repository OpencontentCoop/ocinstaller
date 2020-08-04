<?php

namespace Opencontent\Installer;

interface InterfaceStepInstaller
{
    public function dryRun();

    public function install();
}