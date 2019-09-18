<?php

namespace Opencontent\Installer\Dumper;

use Symfony\Component\Yaml\Yaml;

class Tool
{
    public static function appendToInstallerSteps($dataDir, $stepData)
    {
        $installerData = Yaml::parse(file_get_contents($dataDir . '/installer.yml'));
        $installerData['steps'][] = $stepData;

        $dataYaml = Yaml::dump($installerData, 10);
        file_put_contents($dataDir . '/installer.yml', $dataYaml);
    }
}