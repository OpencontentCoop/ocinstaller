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
$options = $script->getOptions('[file:][dry-run]', '[data]', []);
$cli = eZCLI::instance();
$script->initialize();

$installerDirectory = rtrim($options['arguments'][0], '/');
if (is_dir($installerDirectory)) {
    $allFiles = eZDir::findSubitems($installerDirectory . '/tagtree_csv', 'f', true);
}

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