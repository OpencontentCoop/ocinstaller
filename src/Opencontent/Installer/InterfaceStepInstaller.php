<?php

namespace Opencontent\Installer;

interface InterfaceStepInstaller
{
    public function install();

    public function dryRun();
}