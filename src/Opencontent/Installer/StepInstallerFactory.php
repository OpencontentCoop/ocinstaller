<?php

namespace Opencontent\Installer;

use eZDBInterface;

class StepInstallerFactory
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var InstallerVars
     */
    protected $installerVars;

    /**
     * @var IOTools
     */
    protected $ioTools;

    /**
     * @var eZDBInterface
     */
    protected $db;

    public function __construct($logger, $installerVars, $ioTools, $db)
    {
        $this->logger = $logger;
        $this->installerVars = $installerVars;
        $this->ioTools = $ioTools;
        $this->db = $db;
    }

    /**
     * @param AbstractStepInstaller $installer
     * @return AbstractStepInstaller|InterfaceStepInstaller
     */
    public function factory(AbstractStepInstaller $installer)
    {
        $installer->setLogger($this->logger);
        $installer->setInstallerVars($this->installerVars);
        $installer->setIoTools($this->ioTools);
        $installer->setDb($this->db);

        return $installer;
    }
}