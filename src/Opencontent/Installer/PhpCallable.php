<?php

namespace Opencontent\Installer;

use Opencontent\OpenApi\Exception;

class PhpCallable extends AbstractStepInstaller
{
    public function dryRun()
    {
        $identifier = $this->step['identifier'];
        if (!is_callable($identifier)){
            throw new Exception("Callable $identifier is not callable");
        }
        $this->logger->info("Install callable $identifier");
    }

    public function install()
    {
        $identifier = $this->step['identifier'];
        if (!is_callable($identifier)){
            throw new Exception("Callable $identifier is not callable");
        }
        $this->logger->info("Install callable $identifier");
        call_user_func($identifier, $this);
    }
}