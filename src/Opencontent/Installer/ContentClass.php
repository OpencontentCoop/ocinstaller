<?php

namespace Opencontent\Installer;

use OCClassTools;
use OCOpenDataClassRepositoryCache;

class ContentClass extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $identifier;

    private $source;

    public function __construct($step)
    {
        $this->identifier = $step['identifier'];
        $sourcePath = "classes/{$this->identifier}.yml";
        $this->source = $this->ioTools->createJsonFile($sourcePath);
    }

    public function install()
    {
        $this->logger->log("Create class $this->identifier");
        $tools = new OCClassTools($this->identifier, true, array(), $this->source);
        $tools->sync();

        OCOpenDataClassRepositoryCache::clearCache();

        $this->ioTools->removeJsonFile($this->source);
    }
}