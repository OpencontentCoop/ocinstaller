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

            return Yaml::parse($data);
        }

        return false;
    }

    public function createJsonFile($source)
    {
        $data = $this->getJsonContents($source);

        if ($data) {
            $filePath = $this->getFile($source);
            $destinationFilePath = substr($filePath, 0, -4) . '.json';
            eZFile::create(basename($destinationFilePath), dirname($destinationFilePath), json_encode($data));

            return $destinationFilePath;
        }

        return false;
    }

    public function removeJsonFile($source)
    {
        $filePath = eZSys::rootDir() . '/' . $this->dataDir . '/' . $source;
        $destinationFilePath = substr($filePath, 0, -4) . '.json';
        @unlink($destinationFilePath);
    }
}