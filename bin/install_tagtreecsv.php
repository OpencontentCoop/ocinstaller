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
$options = $script->getOptions('[file:]', '', []);
$cli = eZCLI::instance();
$script->initialize();

$file = $options['file'];
if (!file_exists($file)){
    $script->shutdown(1, "File $file not found");
}else {
    $error = false;
    eZSiteData::create('ocinstall_ttc_' . md5($file), 1)->store();
    try {
        $installer = new TagTreeCsv();
        $installer->setLogger(new Logger());
        $installer->syncTagList($options['file'], true);
    }catch (Exception $e){
        $error = $e->getMessage();
    }
    eZSiteData::fetchByName('ocinstall_ttc_' . md5($file))->remove();
    $script->shutdown(intval($error !== false), $error);
}