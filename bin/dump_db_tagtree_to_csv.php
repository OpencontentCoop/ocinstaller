<?php

require 'autoload.php';

use Opencontent\Installer\TagTreeCsv;
use Symfony\Component\Yaml\Yaml;

$script = eZScript::instance([
    'description' => "Dump tagtree in yml",
    'use-session' => false,
    'use-modules' => false,
    'debug-timing' => true
]);

$script->startup();
$options = $script->getOptions('[parent_tag_id:][data:][drop]',
    '',
    array(
        'parent_tag_id' => "Parent tag id",
        'data' => "Directory of installer data",
        'drop' => "Drop view",
    )
);
$script->initialize();
$cli = eZCLI::instance();
$parentTagId = (int)$options['parent_tag_id'];
try {
    if ($options['drop']) TagTreeCsv::dropTagList();
    TagTreeCsv::createTagList();
    TagTreeCsv::refreshTagList();

    $db = \eZDB::instance();
    $sql = "select * from ocinstall_tags where path_string like '/{$parentTagId}%' order by path_string;";

    $rows = $db->arrayQuery($sql);
    if (count($rows)) {
        $firstRow = array_shift($rows);
        array_unshift($rows, $firstRow);
        array_unshift($rows, array_keys($firstRow));
        if ($options['data']) {
            $directory = rtrim($options['data'], '/') . '/tagtree_csv';
            $filename = eZCharTransform::instance()->transformByGroup($firstRow['keyword_it'], 'identifier') . '.csv';
            $delimiter = ',';
            $enclosure = '"';
            $fp = fopen($directory . '/' . $filename, 'w');
            foreach ($rows as $line) {
                fputcsv($fp, $line, $delimiter, $enclosure);
            }
            fclose($fp);
            $cli->warning($directory . '/' . $filename);
        }
    }
}catch (Exception $e){
    $cli->error($e->getMessage());
}
if ($options['drop']) TagTreeCsv::dropTagList();

$script->shutdown();