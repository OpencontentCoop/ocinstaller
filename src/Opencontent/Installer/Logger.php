<?php

namespace Opencontent\Installer;

use eZCLI;
use eZLog;
use eZINI;
use eZDir;
use Psr\Log\LoggerInterface;

class Logger implements LoggerInterface
{
    private $logName;

    private $logDir;

    public $isVerbose = false;

    public function __construct()
    {
        $time = time();
        $this->logName = 'installer_' . $time . '.log';
        $varDir = eZINI::instance()->variable( 'FileSettings', 'VarDir' );
        $this->logDir = $varDir . '/log';
        eZDir::mkdir($this->logDir, false, true);
    }

    public function emergency($message, array $context = array())
    {
        eZCLI::instance()->error($message);
        $this->write('emergency', $message);
    }

    public function alert($message, array $context = array())
    {
        eZCLI::instance()->error($message);
        $this->write('alert', $message);
    }

    public function critical($message, array $context = array())
    {
        eZCLI::instance()->error($message);
        $this->write('critical', $message);
    }

    public function error($message, array $context = array())
    {
        eZCLI::instance()->error($message);
        $this->write('error', $message);
    }

    public function warning($message, array $context = array())
    {
        eZCLI::instance()->warning($message);
        $this->write('warning', $message);
    }

    public function notice($message, array $context = array())
    {
        $color = eZCLI::instance()->terminalStyle('white');
        $colorEnd = eZCLI::instance()->terminalStyle('white-end');
        $normal = eZCLI::instance()->terminalStyle('normal');
        eZCLI::instance()->output($color.$message.$colorEnd.$normal);
        $this->write('notice', $message);
    }

    public function info($message, array $context = array())
    {
        eZCLI::instance()->notice($message);
        $this->write('info', $message);
    }

    public function debug($message, array $context = array())
    {
        if ($this->isVerbose) {
            $color = eZCLI::instance()->terminalStyle('cyan');
            $colorEnd = eZCLI::instance()->terminalStyle('cyan-end');
            $normal = eZCLI::instance()->terminalStyle('normal');
            eZCLI::instance()->output($color.$message.$colorEnd.$normal);
        }
        $this->write('debug', $message);
    }

    public function log($level, $message, array $context = array())
    {
        eZCLI::instance()->output($message);
        $this->write('log', $message);
    }

    private function write($level, $message)
    {
        eZLog::write('[' . str_pad($level, 9, ' ', STR_PAD_BOTH) . '] ' . $message, $this->logName, $this->logDir);
    }

    public function getLogFilePath()
    {
        return $this->logDir . '/' . $this->logName;
    }
}