<?php

namespace Opencontent\Installer;

class ContentClassWithExtra extends ChainStepInstaller
{
    public function __construct()
    {
        $this->stepInstallers = [
            new ContentClass(),
            new ContentClassExtra()
        ];
    }
}