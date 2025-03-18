<?php

namespace Opencontent\Installer\TagTreeCsv;

use eZDB;

class Projection
{
    const VIEW_NAME = 'ocinstall_tags_tree';

    private $languages;

    public function __construct(array $languages = [])
    {
        $this->languages = $languages;
        $this->create();
    }

    private function create()
    {
        $db = eZDB::instance();

        $tableCreateSql = "CREATE TABLE IF NOT EXISTS eztags_description (
           keyword_id integer not null default 0,
           description_text TEXT,
           locale varchar(255) NOT NULL default '',
           PRIMARY KEY (keyword_id, locale)
        );";
        $db->query($tableCreateSql);

        $keywordColumns = '';
        $synonymsColumns = '';
        $descriptionColumns = '';
        $keywordColumnsForHash = '';
        $synonymsColumnsForHash = '';
        $descriptionColumnsForHash = '';
        foreach ($this->languages as $language => $code) {
            $keywordColumns .= "TRIM(t.keywords->>'$language') as keyword_$code,";
            $synonymsColumns .= "array_to_string(array_remove(array_agg(TRIM(s.keywords->>'$language') order by s.keywords->>'$language'), null), ';') as synonyms_$code,";
            $descriptionColumns .= "TRIM(d.text->>'$language') as description_$code,";
            $keywordColumnsForHash .= "TRIM(t.keywords->>'$language'),";
            $synonymsColumnsForHash .= "array_to_string(array_remove(array_agg(TRIM(s.keywords->>'$language') order by s.keywords->>'$language'), null), ';'),";
            $descriptionColumnsForHash .= "TRIM(d.text->>'$language'),";
        }
        $descriptionColumnsForHash = rtrim($descriptionColumnsForHash, ',');

        $viewName = self::VIEW_NAME;
        $viewQuery = "
        CREATE MATERIALIZED VIEW IF NOT EXISTS $viewName AS
            with tag_list as (
              SELECT t.id, t.main_tag_id, t.parent_id, t.remote_id, t.path_string, jsonb_object_agg(k.locale, k.keyword) as keywords 
                FROM eztags t, eztags_keyword k 
                WHERE t.id = k.keyword_id and t.main_tag_id = 0 
                GROUP BY t.id order by parent_id, id, path_string
            ),
            description_list as (
              SELECT keyword_id, jsonb_object_agg(locale, description_text) as text 
                FROM eztags_description 
                GROUP BY keyword_id order by keyword_id
            ),
            synonym_list as (
              SELECT t.id, t.main_tag_id, t.parent_id, t.remote_id, jsonb_object_agg(k.locale, k.keyword) as keywords 
                FROM eztags t, eztags_keyword k WHERE t.id = k.keyword_id and t.main_tag_id != 0 
                GROUP BY t.id order by path_string
            )
            SELECT t.parent_id, t.id, t.remote_id, t.path_string,
              $keywordColumns 
              $synonymsColumns
              $descriptionColumns
              md5(concat(
                  $keywordColumnsForHash
                  $synonymsColumnsForHash
                  $descriptionColumnsForHash
              )) as hash        
            FROM tag_list t 
                FULL OUTER join synonym_list s on (s.main_tag_id = t.id)
                FULL OUTER JOIN description_list d on (d.keyword_id = t.id)  
            GROUP BY t.parent_id, t.id, t.main_tag_id, t.remote_id, t.keywords, t.path_string, d.text;
        ";

        $db->query($viewQuery);
    }

    public function refresh(): Projection
    {
        $this->drop();
        $this->create();
        eZDB::instance()->query('REFRESH MATERIALIZED VIEW ' . self::VIEW_NAME);
        return $this;
    }

    private function drop(): Projection
    {
        eZDB::instance()->query('DROP MATERIALIZED VIEW IF EXISTS ' . self::VIEW_NAME);
        return $this;
    }

    private function getTreeByParentId(int $parentId): TagTree
    {
        $view = self::VIEW_NAME;
        $pathStringLikeSql = "'/{$parentId}/%'";
        $query = "SELECT * FROM $view  
                  WHERE path_string LIKE $pathStringLikeSql";
        $rows = eZDB::instance()->arrayQuery($query);
        $items = [];
        foreach ($rows as $row) {
            $items[] = TagTreeItem::fromArray($row);
        }

        return new TagTree($items);
    }


    public function getTreeDiffByParentId(int $parentId, TagTree $tagTree = null): TagTree
    {
        $view = self::VIEW_NAME;
        $pathStringLikeSql = "'/{$parentId}/%'";
        $hashFilter = '';
        if ($tagTree instanceof TagTree) {
            $hashList = array_unique($tagTree->getHashList());
            $hashList = array_map(function ($hash) {
                return eZDB::instance()->escapeString($hash);
            }, $hashList);
            $hashFilter = "AND hash NOT IN ('" . implode("','", $hashList) . "')";
        }
        $query = "SELECT * FROM $view  
                  WHERE path_string LIKE $pathStringLikeSql $hashFilter";
        $rows = eZDB::instance()->arrayQuery($query);


        $localTree = $this->getTreeByParentId($parentId);
        $localHashList = $localTree->getHashList();

        $addItems = [];
        foreach ($tagTree->getItems() as $item) {
            $localItem = $localTree->findSimilar($item);
            if (!in_array($item->hash, $localHashList) && !$localItem) {
                $addItems[$item->getPath()] = $item; //->setContext($localTree);
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $item = TagTreeItem::fromArray($row, $localTree);
            $items[$item->getPath()] = $item;
            if (isset($addItems[$item->getPath()])) {
                unset($addItems[$item->getPath()]);
            }
        }

        $data = array_merge($addItems, $items);
        ksort($data);
        return new TagTree($data);
    }
}