<?php

use Opencontent\Installer\Logger;
use Opencontent\Installer\TagTreeCsv;

require 'autoload.php';

$script = eZScript::instance([
    'description' => "Installer tag tree csv",
    'use-session' => false,
    'use-extensions' => true,
    'use-modules' => false,
    'debug-timing' => true,
]);

$script->startup();
$options = $script->getOptions(
    '',
    '[data]',
    []
);
$script->initialize();

$cli = eZCLI::instance();
$output = new ezcConsoleOutput();

$installerDirectory = rtrim($options['arguments'][0], '/');
$cli->warning("Using installer directory $installerDirectory");

$error = false;
try {
    $googleSpreadsheetUrl = 'https://docs.google.com/spreadsheets/d/1Sr21vupXSjru__6NfteiFbM6kmarNhyETzYgjSp_ngc';
    $question = ezcConsoleQuestionDialog::YesNoQuestion(
        $output,
        "Utilizzo il Google Spreadsheet predefinito: $googleSpreadsheetUrl",
        "y"
    );
    if (ezcConsoleDialogViewer::displayDialog($question) == "n") {
        $opts = new ezcConsoleQuestionDialogOptions();
        $opts->text = "Inserisci l'url del Google Spreadsheet";
        $opts->showResults = true;
        $question = new ezcConsoleQuestionDialog($output, $opts);
        $googleSpreadsheetUrl = ezcConsoleDialogViewer::displayDialog($question);
    }

    $googleSpreadsheetTemp = explode(
        '/',
        str_replace('https://docs.google.com/spreadsheets/d/', '', $googleSpreadsheetUrl)
    );
    $googleSpreadsheetId = array_shift($googleSpreadsheetTemp);
    $sheet = new \Opencontent\Google\GoogleSheet($googleSpreadsheetId);
    $sheets = $sheet->getSheetTitleList();

    foreach ($sheets as $index => $sheetName) {
        if (strpos($sheetName, 'OpenCityTag-') === false) {
            unset($sheets[$index]);
        }
    }

    $menu = new ezcConsoleMenuDialog($output);
    $menu->options = new ezcConsoleMenuDialogOptions();
    $menu->options->text = "Please choose a possibility:\n";
    $menu->options->validator = new ezcConsoleMenuDialogDefaultValidator(array_merge($sheets, ['*' => 'All']));
    $userChoice = ezcConsoleDialogViewer::displayDialog($menu);

    if ($userChoice === '*'){
        $choices = array_keys($sheets);
    }else {
        $choices = [$userChoice];
    }

    function getCsvData($filepath)
    {
        $fp = @fopen($filepath, 'r');
        $headers = [];
        $source = [];
        $i = 0;
        while ($row = fgetcsv($fp, 100000000, ',', '"')) {
            if ($i == 0) {
                $headers = $row;
                $headers[] = 'hash';
            } else {
                $row['hash'] = '';
                $rowWithHeader = array_combine($headers, $row);
                $source[] = $rowWithHeader;
            }
            $i++;
        }
        @fclose($filepath);

        return $source;
    }

    function updateLocalItem($remoteItem, $index, &$localData)
    {
        global $cli;

        if ($remoteItem['ger-DE'] !== $localData[$index]['keyword_de']) {
            $localData[$index]['keyword_de'] = $remoteItem['ger-DE'];
            $cli->output(' - Update de ' . $remoteItem['ita-IT'] . ' with ' . $remoteItem['ger-DE']);
        }
        if ($remoteItem['eng-GB'] !== $localData[$index]['keyword_en']) {
            $localData[$index]['keyword_en'] = $remoteItem['eng-GB'];
            $cli->output(' - Update en ' . $remoteItem['ita-IT'] . ' with ' . $remoteItem['eng-GB']);
        }
    }

    foreach ($choices as $choice){
        $map = [
            'OpenCityTag-Comunicazione' => 'tipi_di_notizia',
            'OpenCityTag-Costi e Prezzi' => 'costi_e_prezzi',
            'OpenCityTag-DataThemes' => 'data_themes_eurovocs',
            'OpenCityTag-Dataset' => 'dataset',
            'OpenCityTag-Documenti' => 'documenti',
            'OpenCityTag-Esito servizi' => 'esito_servizi_al_cittadino',
            'OpenCityTag-Eventi' => 'eventi',
            'OpenCityTag-Lingue' => 'lingue_in_cui_e_disponibile_un_servizio_un_evento',
            'OpenCityTag-Licenze' => 'licenze',
            'OpenCityTag-Luoghi' => 'luoghi',
            'OpenCityTag-Organizzazione' => 'organizzazione',
            'OpenCityTag-Persone' => 'persone',
            'OpenCityTag-Servizi pubblici' => 'servizi_pubblici',
        ];

        $sheetName = $sheets[$choice];
        $filepath = isset($map[$sheetName]) ? $installerDirectory . '/tagtree_csv/' . $map[$sheetName] . '.csv' : false;
        if (!file_exists($filepath)) {
            throw new RuntimeException('File csv not found in installer directory ' . $filepath);
        }


        $cli->warning($filepath);
        $localData = $cloneLocalData = getCsvData($filepath);
        $remoteData = $sheet->getSheetDataHash($sheetName);
        foreach ($remoteData as $remoteItem) {
            $remoteItemIta = $remoteItem['ita-IT'] ?? false;
            if ($remoteItemIta) {
                foreach ($cloneLocalData as $index => $localItem) {
                    if ($localItem['keyword_it'] == $remoteItemIta) {
                        updateLocalItem($remoteItem, $index, $localData);
                    }
                }
            }
        }
        if (count($localData)) {
            $delimiter = ',';
            $enclosure = '"';
            $fp = fopen($filepath, 'w');
            fputcsv($fp, array_keys($localData[0]), $delimiter, $enclosure);
            foreach ($localData as $line) {
                fputcsv($fp, array_values($line), $delimiter, $enclosure);
            }
            fclose($fp);
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
    $cli->error($error);
}
$script->shutdown(intval($error !== false), $error);
