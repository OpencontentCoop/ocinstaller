<?php

namespace Opencontent\Installer;

use eZCLI;
use eZLog;
use eZINI;
use eZDir;
use Psr\Log\LoggerInterface;

class Logger implements LoggerInterface
{
    protected $logName;

    protected $logDir;

    public $isVerbose = false;

    protected $prefix;

    protected $prevPrefix;

    public function __construct($prefix = '')
    {
        $time = time();
        $this->logName = 'installer_' . $time . '.log';
        $varDir = eZINI::instance()->variable('FileSettings', 'VarDir');
        $this->logDir = $varDir . '/log';
        eZDir::mkdir($this->logDir, false, true);
        $this->prefix = $this->prevPrefix = $prefix;
    }

    public function setPrefix(string $prefix): Logger
    {
        $this->prevPrefix = $this->prefix;
        $this->prefix = $prefix;
        return $this;
    }

    public function resetPrefix(): Logger
    {
        $this->prefix = $this->prevPrefix;
        return $this;
    }

    protected function decorateMessage($message)
    {
        return $this->prefix . $message;
    }

    public function emergency($message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        eZCLI::instance()->error($message);
        $this->write('emergency', $message);
    }

    public function alert($message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        eZCLI::instance()->error($message);
        $this->write('alert', $message);
    }

    public function critical($message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        eZCLI::instance()->error($message);
        $this->write('critical', $message);
    }

    public function error($message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        eZCLI::instance()->error($message);
        $this->write('error', $message);
    }

    public function warning($message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        eZCLI::instance()->warning($message);
        $this->write('warning', $message);
    }

    public function notice($message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        $color = eZCLI::instance()->terminalStyle('white');
        $colorEnd = eZCLI::instance()->terminalStyle('white-end');
        $normal = eZCLI::instance()->terminalStyle('normal');
        eZCLI::instance()->output($color . $message . $colorEnd . $normal);
        $this->write('notice', $message);
    }

    public function info($message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        eZCLI::instance()->notice($message);
        $this->write('info', $message);
    }

    public function debug($message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        if ($this->isVerbose) {
            $color = eZCLI::instance()->terminalStyle('cyan');
            $colorEnd = eZCLI::instance()->terminalStyle('cyan-end');
            $normal = eZCLI::instance()->terminalStyle('normal');
            eZCLI::instance()->output($color . $message . $colorEnd . $normal);
        }
        $this->write('debug', $message);
    }

    public function log($level, $message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        eZCLI::instance()->output($message);
        $this->write('log', $message);
    }

    protected function write($level, $message)
    {
        eZLog::write('[' . str_pad($level, 9, ' ', STR_PAD_BOTH) . '] ' . $message, $this->logName, $this->logDir);
    }

    public function getLogFilePath()
    {
        return $this->logDir . '/' . $this->logName;
    }
}