<?php

namespace Opencontent\Installer;

use Symfony\Component\Yaml\Yaml;
use eZSys;
use eZFile;

class IOTools
{
    protected $dataDir;

    /**
     * @var InstallerVars
     */
    protected $installerVars;

    public function __construct($dataDir, $installerVars)
    {
        $this->dataDir = $dataDir;
        $this->installerVars = $installerVars;
    }

    public function getFile($source)
    {
        $filePath = \eZSys::rootDir() . '/' . $this->dataDir . '/' . $source;

        if (file_exists($filePath)) {
            return $filePath;
        }

        return false;
    }

    public function getJsonContents($source)
    {
        $filePath = $this->getFile($source);

        if (file_exists($filePath)) {
            $data = file_get_contents($filePath);
            $data = $this->installerVars->filter($data);
            $json = Yaml::parse($data);
            $this->installerVars->validate($json, $source);

            return $json;
        }

        return false;
    }

    /**
     * @return mixed
     */
    public function getDataDir()
    {
        return $this->dataDir;
    }


}