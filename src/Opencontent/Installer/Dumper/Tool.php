<?php

namespace Opencontent\Installer\Dumper;

use Symfony\Component\Yaml\Yaml;

class Tool
{
    public static function appendToInstallerSteps($dataDir, $stepData)
    {
        $output = new \ezcConsoleOutput();
        $question = \ezcConsoleQuestionDialog::YesNoQuestion($output, "Append to installer.yml", "y");
        if (\ezcConsoleDialogViewer::displayDialog($question) == "y") {

            $installerData = Yaml::parse(file_get_contents($dataDir . '/installer.yml'));
            $installerData['steps'][] = $stepData;

            $dataYaml = Yaml::dump($installerData, 10);
            file_put_contents($dataDir . '/installer.yml', $dataYaml);

            return true;
        }

        return false;
    }

    public static function slugize($name)
    {
        $trans = \eZCharTransform::instance();
        return $trans->transformByGroup($name, 'urlalias');
    }
}