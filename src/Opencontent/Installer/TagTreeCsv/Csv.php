<?php

namespace Opencontent\Installer\TagTreeCsv;

class Csv
{
    private $filepath;

    private $languages;

    private $isLoaded = false;

    private $sourceList = [];

    private $treeItems = [];

    public function __construct(string $filepath, array $languages = [])
    {
        $this->filepath = $filepath;
        $this->languages = $languages;
        $this->load();
    }

    private function load()
    {
        if (!$this->isLoaded) {
            $fp = fopen($this->filepath, 'r');
            $headers = [];
            $this->sourceList = [];
            $i = -1;
            while ($row = fgetcsv($fp, 100000000, ',', '"')) {
                $i++;
                if ($i == 0) {
                    $headers = $row;
                } else {
                    $row = array_map('trim', $row);
                    $rowWithHeader = array_combine($headers, $row);
                    foreach ($this->languages as $code) {
                        $rowWithHeader['synonyms_' . $code] = $this->formatSynonymList(
                            $rowWithHeader['synonyms_' . $code] ?? ''
                        );
                        if (!isset($rowWithHeader['keyword_' . $code])) {
                            $rowWithHeader['keyword_' . $code] = '';
                        }
                        if (!isset($rowWithHeader['description_' . $code])) {
                            $rowWithHeader['description_' . $code] = '';
                        }
                    }
                    if (empty(array_filter($rowWithHeader))) {
                        continue;
                    }
                    $rowWithHeader['hash'] = $this->calcolateHash($rowWithHeader);
                    $this->sourceList[$rowWithHeader['hash']] = $rowWithHeader;
                    $this->treeItems[] = TagTreeItem::fromArray($rowWithHeader);
                }
            }
            @fclose($this->filepath);
            $this->isLoaded = true;
        }
    }

    private function formatSynonymList($list)
    {
        $list = str_replace('; ', ';', $list);
        $array = explode(';', $list);
        sort($array);
        return implode(';', $array);
    }

    private function calcolateHash(array $row): string
    {
        $data = '';
        foreach ($this->languages as $code) {
            $data .= $row['keyword_' . $code] ?? '';
        }
        foreach ($this->languages as $code) {
            $data .= $row['synonyms_' . $code] ?? '';
        }
        foreach ($this->languages as $code) {
            $data .= $row['description_' . $code] ?? '';
        }

        return md5($data);
    }

    public function getTree(): TagTree
    {
        return new TagTree($this->treeItems);
    }
}