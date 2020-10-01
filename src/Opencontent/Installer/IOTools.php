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

    private static $avoidImportRecursion = [];

    public function __construct($dataDir, $installerVars)
    {
        $this->dataDir = $dataDir;
        $this->installerVars = $installerVars;
    }

    public function getFile($source)
    {
        if (substr($this->dataDir, 0, 1) === '/'){
            $filePath = $this->dataDir . '/' . $source;
        }else{
            $filePath = \eZSys::rootDir() . '/' . $this->dataDir . '/' . $source;
        }

        if (file_exists($filePath)) {
            return realpath($filePath);
        }

        return false;
    }

    public function getFileContents($source)
    {
        $filePath = $this->getFile($source);

        if (file_exists($filePath)) {
            $data = file_get_contents($filePath);

            return $data;
        }

        return false;
    }

    public function getJsonContents($source, $parentSource = null)
    {
        $filePath = $this->getFile($source);

        $avoidRecursionKey = $parentSource ? $parentSource : $source;
        if (!isset(self::$avoidImportRecursion[$avoidRecursionKey])){
            self::$avoidImportRecursion[$avoidRecursionKey] = [];
        }

        if (file_exists($filePath)) {
            $data = file_get_contents($filePath);
            $data = $this->installerVars->filter($data);
            $json = Yaml::parse($data);

            if (isset($json['imports'])){
                if (in_array($source, self::$avoidImportRecursion[$avoidRecursionKey])){
                    throw new \Exception("Found import recursion {$source} in {$avoidRecursionKey}");
                }
                self::$avoidImportRecursion[$avoidRecursionKey] = $source;
                foreach ($json['imports'] as $import){
                    if (isset($import['resource'])){
                        if (in_array($import['resource'], self::$avoidImportRecursion[$avoidRecursionKey])){
                            throw new \Exception("Found import recursion {$import['resource']} in {$avoidRecursionKey}");
                        }
                        self::$avoidImportRecursion[$avoidRecursionKey] = $import['resource'];
                        $importData = $this->getJsonContents($import['resource'], $avoidRecursionKey);
                        if (!$importData){
                            throw new \Exception("Fail importing resource {$import['resource']}");
                        }
                        $json = array_merge_recursive($json, $importData);
                    }
                }
                unset($json['imports']);
                self::$avoidImportRecursion[$avoidRecursionKey] = [];
            }

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