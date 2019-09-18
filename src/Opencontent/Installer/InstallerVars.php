<?php

namespace Opencontent\Installer;


class InstallerVars extends \ArrayObject
{
    /**
     * @var Logger
     */
    private $logger;

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

    public function offsetSet($name, $value)
    {
        $value = $this->parseVarValue($value);

        if ($this->logger->isVerbose)
            $this->logger->warning(" -> $" . $name . ": $value");

        parent::offsetSet($name, $value);
    }

    public function filter($data)
    {
        foreach ($this as $name => $value) {
            $data = str_replace('$' . $name, $value, $data);
        }

        return $data;
    }

    protected function parseVarValue($value)
    {
        if (strpos($value, '::') !== false) {
            list($class, $method) = explode('::', $value);
            if (method_exists($class, $method)){
                $value = $class::{$method}();
            }
        }

        if (strpos($value, 'env(') !== false) {
            $envVariable = substr($value, 4, -1);
            $value = isset($_ENV[$envVariable]) ? $_ENV[$envVariable] : false;
        }

        if (strpos($value, 'ini(') !== false) {
            $iniVariable = substr($value, 4, -1);
            list($group, $variable, $file) = explode(',', $iniVariable);
            $value = \eZINI::instance($file)->variable($group, $variable);
        }

        return $value;
    }

}