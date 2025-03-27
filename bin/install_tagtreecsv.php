<?php

use Opencontent\Installer\Logger;
use Opencontent\Installer\TagTreeCsvV1 as TagTreeCsv;

require 'autoload.php';

$script = eZScript::instance([
    'description' => "Installer tag tree csv",
    'use-session' => false,
    'use-extensions' => true,
    'use-modules' => false,
    'debug-timing' => true,
]);

$script->startup();
$options = $script->getOptions('[file:][dry-run]', '[data]', []);
$cli = eZCLI::instance();
$script->initialize();

$installerDirectory = rtrim($options['arguments'][0], '/');
$allFiles = [
    $installerDirectory . '/tagtree_csv/tipi_di_notizia.csv',
    $installerDirectory . '/tagtree_csv/costi_e_prezzi.csv',
    $installerDirectory . '/tagtree_csv/data_themes_eurovocs.csv',
    $installerDirectory . '/tagtree_csv/dataset.csv',
    $installerDirectory . '/tagtree_csv/documenti.csv',
    $installerDirectory . '/tagtree_csv/esito_servizi_al_cittadino.csv',
    $installerDirectory . '/tagtree_csv/eventi.csv',
    $installerDirectory . '/tagtree_csv/lingue_in_cui_e_disponibile_un_servizio_un_evento.csv',
    $installerDirectory . '/tagtree_csv/licenze.csv',
    $installerDirectory . '/tagtree_csv/luoghi.csv',
    $installerDirectory . '/tagtree_csv/organizzazione.csv',
    $installerDirectory . '/tagtree_csv/persone.csv',
    $installerDirectory . '/tagtree_csv/servizi_pubblici.csv',
];

$files = $options['file'] ?? $allFiles;
if (!is_array($files)){
    $files = [$files];
}

$error = false;

foreach ($files as $file) {
    if (!file_exists($file)) {
        $script->shutdown(1, "File $file not found");
    } else {
        $cli->warning($file);
        eZSiteData::create('ocinstall_ttc_' . md5($file), 1)->store();
        try {
            TagTreeCsv::dropTagList();
            TagTreeCsv::createTagList();
            TagTreeCsv::refreshTagList();
            $installer = new TagTreeCsv();
            $logger = new Logger();
            $logger->isVerbose = $options['verbose'];
            $installer->setLogger($logger);
            $doUpdate = !$options['dry-run'];
            $doRemove = !$options['dry-run'];
            $installer->syncTagList($file, $doUpdate, $doRemove);
        } catch (Exception $e) {
            $error = $e->getMessage();
            $cli->error($error);
        }
        eZSiteData::fetchByName('ocinstall_ttc_' . md5($file))->remove();
    }
}

$script->shutdown(intval($error !== false), $error);