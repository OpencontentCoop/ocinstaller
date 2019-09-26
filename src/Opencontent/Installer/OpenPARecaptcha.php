<?php

namespace Opencontent\Installer;


class OpenPARecaptcha extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function install()
    {
        $this->logger->info("Install recaptcha keys");
    }

    public function dryRun()
    {
        $this->logger->info("Install recaptcha keys");
        $public = trim($this->step['public']);
        $private = trim($this->step['private']);
        $recaptcha = new \OpenPARecaptcha();
        $recaptcha->store($public, $private);
    }

}