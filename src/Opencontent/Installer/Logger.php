<?php

namespace Opencontent\Installer;

use eZCLI;

class Logger
{
    public function log($message)
    {
        eZCLI::instance()->output($message);
    }

    public function warning($message)
    {
        eZCLI::instance()->warning($message);
    }

}