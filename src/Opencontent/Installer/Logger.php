<?php

namespace Opencontent\Installer;

use eZCLI;
use Psr\Log\LoggerInterface;

class Logger implements LoggerInterface
{
    public $isVerbose = false;

    public function emergency($message, array $context = array())
    {
        eZCLI::instance()->error($message);
    }

    public function alert($message, array $context = array())
    {
        eZCLI::instance()->error($message);
    }

    public function critical($message, array $context = array())
    {
        eZCLI::instance()->error($message);
    }

    public function error($message, array $context = array())
    {
        eZCLI::instance()->error($message);
    }

    public function warning($message, array $context = array())
    {
        eZCLI::instance()->warning($message);
    }

    public function notice($message, array $context = array())
    {
        $color = eZCLI::instance()->terminalStyle('white');
        $colorEnd = eZCLI::instance()->terminalStyle('white-end');
        $normal = eZCLI::instance()->terminalStyle('normal');
        eZCLI::instance()->output($color.$message.$colorEnd.$normal);
    }

    public function info($message, array $context = array())
    {
        eZCLI::instance()->notice($message);
    }

    public function debug($message, array $context = array())
    {
        if ($this->isVerbose) {
            $color = eZCLI::instance()->terminalStyle('cyan');
            $colorEnd = eZCLI::instance()->terminalStyle('cyan-end');
            $normal = eZCLI::instance()->terminalStyle('normal');
            eZCLI::instance()->output($color.$message.$colorEnd.$normal);
        }
    }

    public function log($level, $message, array $context = array())
    {
        eZCLI::instance()->output($message);
    }

}