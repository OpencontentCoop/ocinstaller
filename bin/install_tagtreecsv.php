<?php

use Opencontent\Installer\Logger;
use Opencontent\Installer\TagTreeCsv;

require 'autoload.php';

$script = eZScript::instance([
    'description' => "Installer tag tree csv",
    'use-session' => false,
    'use-extensions' => true,
    'use-modules' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[file:][dry-run]', '', []);
$cli = eZCLI::instance();
$script->initialize();

$file = $options['file'];
if (!file_exists($file)){
    $script->shutdown(1, "File $file not found");
}else {
    $error = false;
    eZSiteData::create('ocinstall_ttc_' . md5($file), 1)->store();
    try {
        TagTreeCsv::createTagList();
        TagTreeCsv::refreshTagList();
        $installer = new TagTreeCsv();
        $logger = new Logger();
        $logger->isVerbose = $options['verbose'];
        $installer->setLogger($logger);
        $doUpdate = !$options['dry-run'];
        $doRemove = !$options['dry-run'];
        $installer->syncTagList($options['file'], $doUpdate, $doRemove);
    }catch (Exception $e){
        $error = $e->getMessage();
        $cli->error($error);
    }
    eZSiteData::fetchByName('ocinstall_ttc_' . md5($file))->remove();
    $script->shutdown(intval($error !== false), $error);
}