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

    public function parseVarValue($value)
    {
        if (is_string($value)) {
            if (strpos($value, '::') !== false) {
                list($class, $method) = explode('::', $value);
                if (method_exists($class, $method)) {
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

            if (strpos($value, 'tag(') !== false) {
                $tagUrl = substr($value, 4, -1);
                $tag = \eZTagsObject::fetchByUrl($tagUrl);
                if ($tag instanceof \eZTagsObject){
                    $value = $tag->attribute('id');
                }else{
                    $value = 0;
                }
            }

            if (strpos($value, 'ezcrc32(') !== false) {
                $var = trim(substr($value, 8, -1));
                $value = \eZSys::ezcrc32($var);
            }
        }

        return $value;
    }

    public function validate($data, $context = '')
    {
        if (is_string($data)){
            $dataString = $data;
        }else {
            $dataString = json_encode($data);
        }
        if (strpos($dataString, '$') !== false){
            $unknowVars = [];
            //@todo
            $tokens = explode(',', $dataString);
            foreach ($tokens as $temp_token){
                $retokens = explode(':', $temp_token);
                foreach ($retokens as $token) {
                    if (strpos($token, '$') !== false) {
                        $token = str_replace([',', '}', '{', '"', ']', '['], '', $token);
                        $unknowVars[] = $token;
                    }
                }
            }
            throw new \Exception("[$context] Unresolved variables: " . implode(', ', $unknowVars));
        }
    }

}