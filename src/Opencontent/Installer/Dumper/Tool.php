<?php

namespace Opencontent\Installer\Dumper;

use Symfony\Component\Yaml\Yaml;

class Tool
{
    public static function appendToInstallerSteps($dataDir, $stepData, $skipQuestion = false)
    {
        $output = new \ezcConsoleOutput();
        $question = \ezcConsoleQuestionDialog::YesNoQuestion($output, "Append to installer.yml", "y");

        $append = true;
        if ($skipQuestion === false){
            $append = \ezcConsoleDialogViewer::displayDialog($question) == "y";
        }

        if ($append) {
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

    public static function createFile($dataDir, $directoryName, $filename, $dataYaml)
    {
        $directory = rtrim($dataDir, '/') . '/' . $directoryName;
        \eZDir::mkdir($directory, false, true);
        \eZFile::create($filename, $directory, $dataYaml);
    }
}