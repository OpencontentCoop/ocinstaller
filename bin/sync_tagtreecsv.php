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
$options = $script->getOptions('[file:][dry-run][remove-deprecated-translations]', '[data]', []);
$cli = eZCLI::instance();
$script->initialize();

$installerDirectory = rtrim(($options['arguments'][0] ?? ''), '/');
if (is_dir($installerDirectory)) {
    $allFiles = eZDir::findSubitems($installerDirectory . '/tagtree_csv', 'f', true);
}

$files = $options['file'] ?? $allFiles;
if (!is_array($files)) {
    $files = [$files];
}

$error = false;

//foreach ($files as $index => $file) {
//    $newName = str_replace('Tassonomie sito web PAT -', '', basename($file));
//    $newName = str_replace(' (1)', '', $newName);
//    $newName = trim($newName);
//    $cli->output($file . ' ' . $newName);
//    eZFile::rename($file, $installerDirectory . '/tagtree_csv/' . $newName);
//}
sort($files);
foreach ($files as $index => $file) {
    if (!file_exists($file)) {
        $script->shutdown(1, "File $file not found");
    }
}
$dryRun = (bool)$options['dry-run'];

$languages = ['ita-IT' => 'it', 'eng-GB' => 'en', 'ita-PA' => 'pa', 'ger-DE' => 'de']; //@todo

$updater = new TagTreeCsv\Updater($languages, $files);
$updater->setLogger(new Logger());
$updater->setDryRun($dryRun);
$updater->setRemoveTranslation((bool)$options['remove-deprecated-translations']);
$updater->setMoveInDeprecated(!$dryRun);

try {
    $updater->run();
} catch (Throwable $e) {
    $error = $e->getMessage();
    $cli->error($error);
    $cli->error($e->getTraceAsString());
}


$script->shutdown(intval($error !== false), $error);