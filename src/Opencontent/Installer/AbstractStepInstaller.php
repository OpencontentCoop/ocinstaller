<?php

namespace Opencontent\Installer;

use eZDBInterface;

abstract class AbstractStepInstaller
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var InstallerVars
     */
    protected $installerVars;

    /**
     * @var IOTools
     */
    protected $ioTools;

    /**
     * @var array
     */
    protected $step;

    /**
     * @var eZDBInterface
     */
    protected $db;

    /**
     * @return Logger
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * @param Logger $logger
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return InstallerVars
     */
    public function getInstallerVars()
    {
        return $this->installerVars;
    }

    /**
     * @param InstallerVars $installerVars
     */
    public function setInstallerVars($installerVars)
    {
        $this->installerVars = $installerVars;
    }

    /**
     * @return IOTools
     */
    public function getIoTools()
    {
        return $this->ioTools;
    }

    /**
     * @param IOTools $ioTools
     */
    public function setIoTools($ioTools)
    {
        $this->ioTools = $ioTools;
    }

    /**
     * @return array
     */
    public function getStep()
    {
        return $this->step;
    }

    /**
     * @param array $step
     */
    public function setStep($step)
    {
        if ($this->installerVars) {
            $stepString = json_encode($step);
            $stepString = $this->installerVars->filter($stepString);
            $this->installerVars->validate($stepString, isset($step['identifier']) ? $step['identifier'] : '');
            $step = json_decode($stepString, true);
        }

        $this->step = $step;
    }

    /**
     * @return eZDBInterface
     */
    public function getDb()
    {
        return $this->db;
    }

    /**
     * @param eZDBInterface $db
     */
    public function setDb($db)
    {
        $this->db = $db;
    }


}