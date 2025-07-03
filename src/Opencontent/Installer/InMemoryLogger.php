<?php

namespace Opencontent\Installer;

class InMemoryLogger extends Logger
{
    protected $logs = [];

    public function emergency($message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        $this->append('emergency', $message);
        $this->write('emergency', $message);
    }

    public function alert($message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        $this->append('alert', $message);
        $this->write('alert', $message);
    }

    public function critical($message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        $this->append('critical', $message);
        $this->write('critical', $message);
    }

    public function error($message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        $this->append('error', $message);
        $this->write('error', $message);
    }

    public function warning($message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        $this->append('warning', $message);
        $this->write('warning', $message);
    }

    public function notice($message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        $this->append('notice', $message);
        $this->write('notice', $message);
    }

    public function info($message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        $this->append('info', $message);
        $this->write('info', $message);
    }

    public function debug($message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        if ($this->isVerbose) {
            $this->append('debug', $message);
        }
        $this->write('debug', $message);
    }

    public function log($level, $message, array $context = [])
    {
        $message = $this->decorateMessage($message);
        $this->append('log', $message);
        $this->write('log', $message);
    }

    protected function format($level, $message)
    {
        return '[' . $level . '] ' . $message;
    }

    public function getLogs(): array
    {
        return $this->logs;
    }

    protected function append($level, $message)
    {
        $message = $this->format($level, $message);
        $this->logs[] = $message;
    }
}