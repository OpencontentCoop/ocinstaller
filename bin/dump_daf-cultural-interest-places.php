<?php

require 'autoload.php';

use Symfony\Component\Yaml\Yaml;

$script = eZScript::instance([
    'description' => "Dump tag list in yml from daf csv",
    'use-session' => false,
    'use-modules' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[data:]',
    '',
    array(
        'data' => "Directory of installer data",
    )
);
$script->initialize();
$cli = eZCLI::instance();

$basePA = 'https://w3id.org/italia/controlled-vocabulary/classifications-for-culture/cultural-interest-places/';
$url = 'https://raw.githubusercontent.com/italia/daf-ontologie-vocabolari-controllati/master/VocabolariControllati/classifications-for-culture/cultural-interest-places/cultural-interest-places.csv';
$remotData = file_get_contents($url);
$rows = explode("\n", $remotData);
$csvData = array();
foreach ($rows as $index => $row) {
    if ($index === 0) {
        $headers = str_getcsv($row);
    } else {
        $csvData[] = array_combine($headers, str_getcsv($row));
    }
}
/*
[Codice_1_livello] => A
[Label_ITA_1_livello] => Architettura militare e fortificata
[Label_ITA_1_livello_alternativa_siti_web_1] => Rocca e castello
[Label_ITA_1_livello_alternativa_siti_web_plurale] => Rocche e castelli
[Label_ITA_1_livello_alternativa_altri_sistemi] =>
[Codice_2_Livello] => A.1
[Label_ITA_2_livello] => Castello
[Label_ITA_2_livello_alternativa_plurale] => Castelli
[Label_ITA_2_livello_alternativa_2] =>
[Definizione] =>
 */
$dataTree = [];
$debugTree = [];
foreach ($csvData as $csvItem) {
    $firstLevel = [
        'Codice_1_livello' => $csvItem['Codice_1_livello'],
        'Label_ITA_1_livello' => $csvItem['Label_ITA_1_livello'],
        'Label_ITA_1_livello_alternativa_siti_web_1' => $csvItem['Label_ITA_1_livello_alternativa_siti_web_1'],
        'Label_ITA_1_livello_alternativa_siti_web_plurale' => $csvItem['Label_ITA_1_livello_alternativa_siti_web_plurale'],
        'Label_ITA_1_livello_alternativa_altri_sistemi' => $csvItem['Label_ITA_1_livello_alternativa_altri_sistemi'],
    ];
    $secondLevel = [
        'Codice_2_Livello' => $csvItem['Codice_2_Livello'],
        'Label_ITA_2_livello' => $csvItem['Label_ITA_2_livello'],
        'Label_ITA_2_livello_alternativa_plurale' => $csvItem['Label_ITA_2_livello_alternativa_plurale'],
        'Label_ITA_2_livello_alternativa_2' => $csvItem['Label_ITA_2_livello_alternativa_2'],
    ];
    if (!isset($dataTree[$csvItem['Label_ITA_1_livello']])) {
        $dataTree[$csvItem['Label_ITA_1_livello']] = [
            'item' => $firstLevel
        ];
    }
    $dataTree[$csvItem['Label_ITA_1_livello']]['children'][$csvItem['Label_ITA_2_livello']] = $secondLevel;
    $debugTree[$csvItem['Label_ITA_1_livello_alternativa_siti_web_plurale']][] = $csvItem['Label_ITA_2_livello'];
    $debugTree[$csvItem['Label_ITA_1_livello_alternativa_siti_web_plurale']] = array_unique($debugTree[$csvItem['Label_ITA_1_livello_alternativa_siti_web_plurale']]);
    sort($debugTree[$csvItem['Label_ITA_1_livello_alternativa_siti_web_plurale']]);
}

$data = [];
foreach ($dataTree as $item){
    if (!empty($item['item']['Label_ITA_1_livello_alternativa_siti_web_plurale'])) {
        $struct = new stdClass();
        $struct->keyword = $item['item']['Label_ITA_1_livello_alternativa_siti_web_plurale'];
        $struct->locale = 'ita-IT';
        $struct->alwaysAvailable = true;
        $struct->keywordTranslations = [
            'ita-IT' => $item['item']['Label_ITA_1_livello_alternativa_siti_web_plurale'],
            'ita-PA' => $basePA . $item['item']['Codice_1_livello']
        ];
        $struct->synonyms = [];
        if (!empty($item['item']['Label_ITA_1_livello'])) {
            $struct->synonyms['ita-IT'][] = $item['item']['Label_ITA_1_livello'];
        }
//        if (!empty($item['item']['Label_ITA_1_livello_alternativa_siti_web_1'])) {
//            $struct->synonyms['ita-IT'][] = $item['item']['Label_ITA_1_livello_alternativa_siti_web_1'];
//        }
        if (!empty($item['item']['Label_ITA_1_livello_alternativa_altri_sistemi'])) {
            $struct->synonyms['ita-IT'][] = $item['item']['Label_ITA_1_livello_alternativa_altri_sistemi'];
        }
        if (isset($struct->synonyms['ita-IT'])) {
            $struct->synonyms['ita-IT'] = array_unique($struct->synonyms['ita-IT']);
        }

        $struct->children = [];
        foreach ($item['children'] as $childItem){
            $structChild = new stdClass();
            $structChild->keyword = $childItem['Label_ITA_2_livello'];
            $structChild->locale = 'ita-IT';
            $structChild->alwaysAvailable = true;
            $structChild->keywordTranslations = [
                'ita-IT' => $childItem['Label_ITA_2_livello'],
                'ita-PA' => $basePA . str_replace('.', '', $childItem['Codice_2_Livello'])
            ];
            $structChild->synonyms = [];
            $structChild->hasChildren = false;
            $structChild->children = [];
//            if (!empty($childItem['Label_ITA_2_livello_alternativa_plurale'])) {
//                $structChild->synonyms['ita-IT'][] = $childItem['Label_ITA_2_livello_alternativa_plurale'];
//            }
            if (!empty($childItem['Label_ITA_2_livello_alternativa_2'])) {
                $structChild->synonyms['ita-IT'][] = $childItem['Label_ITA_2_livello_alternativa_2'];
            }
            if (isset($structChild->synonyms['ita-IT'])) {
                $structChild->synonyms['ita-IT'] = array_unique($structChild->synonyms['ita-IT']);
            }
            $struct->children[] = (array)$structChild;
        }
        $struct->hasChildren = count($struct->children) > 0;

        $data[] = (array)$struct;
    }
}

$tipi = new stdClass();
$tipi->keyword = 'Tipi di luogo';
$tipi->locale = 'ita-IT';
$tipi->alwaysAvailable = true;
$tipi->keywordTranslations = [
    'ita-IT' => 'Tipi di luogo',
];
$tipi->synonyms = [];
$tipi->children = $data;
$tipi->hasChildren = true;


$luoghi = new stdClass();
$luoghi->keyword = 'Luoghi';
$luoghi->locale = 'ita-IT';
$luoghi->alwaysAvailable = true;
$luoghi->keywordTranslations = [
    'ita-IT' => 'Luoghi',
];
$luoghi->synonyms = [];
$luoghi->children[] = (array)$tipi;
$luoghi->hasChildren = true;


if ($options['data']) {

    $dataYaml = Yaml::dump((array)$luoghi, 10);
    $identifier = \Opencontent\Installer\Dumper\Tool::slugize('Tipi-di-luogo');
    $filename = $identifier . '.yml';

    \Opencontent\Installer\Dumper\Tool::createFile(
        $options['data'],
        'tagtree',
        $identifier . '.yml',
        $dataYaml
    );

    \Opencontent\Installer\Dumper\Tool::appendToInstallerSteps($options['data'], [
        'type' => 'tagtree',
        'identifier' => $identifier
    ]);

} else {
    print_r(Yaml::dump((array)$luoghi, 10));
}


$script->shutdown();