<?php

namespace Opencontent\Installer;


class OpenPARecaptcha extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $version;

    public function __construct($version = null)
    {
        $this->version = $version ? (int)$version : 2;
    }

    public function dryRun(): void
    {
        $this->logger->info("Install recaptcha {$this->version} keys");
    }

    public function install(): void
    {
        $this->logger->info("Install recaptcha {$this->version} keys");
        $public = trim($this->step['public']);
        $private = trim($this->step['private']);
        if (!empty($public) && !empty($private)) {
            $recaptcha = new \OpenPARecaptcha($this->version);
            $recaptcha->store($public, $private);
        }else{
            $this->getLogger()->error('Recaptcha keys are empty');
        }
    }

}