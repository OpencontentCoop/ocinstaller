<?php

namespace Opencontent\Installer;

use Opencontent\Opendata\Api\Structs\TagStruct;
use Opencontent\Opendata\Api\Structs\TagSynonymStruct;
use Opencontent\Opendata\Api\Structs\TagTranslationStruct;
use Opencontent\Opendata\Api\TagRepository;
use Exception;
use Opencontent\Opendata\Api\Values\Tag;


class TagTreeCsv extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $tagRepository;

    private $async = false;

    public function __construct()
    {
        $this->tagRepository = new TagRepository();
    }

    public function dryRun()
    {
        self::createTagList();
        self::refreshTagList();
        $identifiers = (array)$this->step['identifiers'];
        foreach ($identifiers as $identifier) {
            if (!$this->ioTools->getFile('tagtree_csv/' . $identifier . '.csv')) {
                throw new Exception('File csv not found');
            }
            $this->logger->info("Install tag tree " . $identifier);
            $this->syncTagList($this->ioTools->getFile('tagtree_csv/' . $identifier . '.csv'), false);
        }
        self::dropTagList();
    }

    /**
     * @throws Exception
     */
    public function install()
    {
        self::createTagList();
        self::refreshTagList();
        if ($this->async) {
            \eZSiteData::removeObject(\eZSiteData::definition(), ['name' => ['like', 'ocinstall_ttc_%']]);
        }
        $identifiers = (array)$this->step['identifiers'];

        foreach ($identifiers as $identifier) {
            $filepath = $this->ioTools->getFile('tagtree_csv/' . $identifier . '.csv');
            if (!$filepath) {
                throw new Exception('File csv not found ' . $filepath);
            }
            $this->logger->info("Install tag tree " . $identifier);
            if ($this->async) {
                $directory = getcwd();
                $siteAccess = \eZSiteAccess::current()['name'];
                $command = 'bash ' . $directory . '/vendor/opencontent/ocinstaller/bin/install_tagtreecsv.sh ' . $siteAccess . ' ' . $filepath . '  > /dev/null &';
                //$this->logger->debug($command);
                shell_exec($command);
            }else {
                $this->syncTagList($filepath, true);
            }
        }
        if ($this->async) {
            sleep(2);
            $result = function () {
                return \eZSiteData::count(\eZSiteData::definition(), ['name' => ['like', 'ocinstall_ttc_%']]);
            };
            $sleep = 1;
            while ($count = $result()) {
                if ($sleep > 300) {
                    throw new Exception("Timeout");
                }
                \eZCLI::instance()->notice('Still ' . $count . ' files to be processed in maximum ' . (300 - $sleep) . ' seconds' );
                $sleep = $sleep + 10;
                sleep($sleep);
            }
        }

        self::dropTagList();
    }

    public static function createTagList()
    {
        $db = \eZDB::instance();
        $viewQuery = "
        CREATE MATERIALIZED VIEW IF NOT EXISTS ocinstall_tags AS
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
              TRIM(t.keywords->>'ita-IT') as keyword_it, 
              TRIM(t.keywords->>'ger-DE') as keyword_de, 
              TRIM(t.keywords->>'ita-PA') as keyword_pa, 
              TRIM(t.keywords->>'eng-GB') as keyword_en, 
              array_to_string(array_remove(array_agg(TRIM(s.keywords->>'ita-IT') order by s.keywords->>'ita-IT'), null), ';') as synonyms_it,
              array_to_string(array_remove(array_agg(TRIM(s.keywords->>'ger-DE') order by s.keywords->>'ger-DE'), null), ';') as synonyms_de,
              array_to_string(array_remove(array_agg(TRIM(s.keywords->>'ita-PA') order by s.keywords->>'ita-PA'), null), ';') as synonyms_pa,
              array_to_string(array_remove(array_agg(TRIM(s.keywords->>'eng-GB') order by s.keywords->>'eng-GB'), null), ';') as synonyms_en,
              TRIM(d.text->>'ita-IT') as description_it,  
              TRIM(d.text->>'ger-DE') as description_de,
              TRIM(d.text->>'eng-GB') as description_en,
              md5(concat(
                  TRIM(t.keywords->>'ita-IT'),
                  TRIM(t.keywords->>'ger-DE'),
                  TRIM(t.keywords->>'ita-PA'),
                  TRIM(t.keywords->>'eng-GB'),
                  array_to_string(array_remove(array_agg(TRIM(s.keywords->>'ita-IT') order by s.keywords->>'ita-IT'), null), ';'),
                  array_to_string(array_remove(array_agg(TRIM(s.keywords->>'ger-DE') order by s.keywords->>'ger-DE'), null), ';'),
                  array_to_string(array_remove(array_agg(TRIM(s.keywords->>'ita-PA') order by s.keywords->>'ita-PA'), null), ';'),
                  array_to_string(array_remove(array_agg(TRIM(s.keywords->>'eng-GB') order by s.keywords->>'eng-GB'), null), ';'),
                  TRIM(d.text->>'ita-IT'),
                  TRIM(d.text->>'ger-DE'),
                  TRIM(d.text->>'eng-GB')
              )) as hash        
            FROM tag_list t 
                FULL OUTER join synonym_list s on (s.main_tag_id = t.id)
                FULL OUTER JOIN description_list d on (d.keyword_id = t.id)  
            GROUP BY t.parent_id, t.id, t.main_tag_id, t.remote_id, t.keywords, t.path_string, d.text;
        ";

        $db->query($viewQuery);
    }

    public static function refreshTagList()
    {
        \eZDB::instance()->query('REFRESH MATERIALIZED VIEW ocinstall_tags');
    }

    public static function dropTagList()
    {
        \eZDB::instance()->query('DROP MATERIALIZED VIEW IF EXISTS ocinstall_tags');
    }

    private function formatSynonymList($list)
    {
        $list = str_replace('; ', ';', $list);
        $array = explode(';', $list);
        sort($array);
        return implode(';', $array);
    }

    public function syncTagList($filepath, $doUpdate = false)
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
                $rowWithHeader['synonyms_it'] = $this->formatSynonymList($rowWithHeader['synonyms_it']);
                $rowWithHeader['synonyms_de'] = $this->formatSynonymList($rowWithHeader['synonyms_de']);
                $rowWithHeader['synonyms_pa'] = $this->formatSynonymList($rowWithHeader['synonyms_pa']);
                $rowWithHeader['synonyms_en'] = $this->formatSynonymList($rowWithHeader['synonyms_en']);
                $rowWithHeader['hash'] = md5(
                    $rowWithHeader['keyword_it'] .
                    $rowWithHeader['keyword_de'] .
                    $rowWithHeader['keyword_pa'] .
                    $rowWithHeader['keyword_en'] .
                    $rowWithHeader['synonyms_it'] .
                    $rowWithHeader['synonyms_de'] .
                    $rowWithHeader['synonyms_pa'] .
                    $rowWithHeader['synonyms_en'] .
                    $rowWithHeader['description_it'] .
                    $rowWithHeader['description_de'] .
                    $rowWithHeader['description_en']
                );
                $source[] = $rowWithHeader;
            }
            $i++;
        }

        $sourceHashes = array_column($source, 'hash');
        $sourceRoot = array_shift($source);

        $rootTag = \eZTagsObject::fetchByRemoteID($sourceRoot['remote_id']);
        if (!$rootTag instanceof \eZTagsObject) {
            $tag = $this->createTag($sourceRoot);
            $rootTag = \eZTagsObject::fetch($tag->id);
        }
        $parentTagId = $rootTag->attribute('id');
        $pathStringLikeSql = "'/{$parentTagId}/%'";
        if ($rootTag->attribute('parent_id') > 0) {
            $pathStringLikeSql = "'%/{$parentTagId}/%'";
        }

        $modifiedSql = "select * from ocinstall_tags  
                            where path_string like $pathStringLikeSql 
                              and hash not in ('" . implode("','", $sourceHashes) . "')";
        $hashIsNotInSource = \eZDB::instance()->arrayQuery($modifiedSql);
        $hashIsNotInSourceRemoteIdList = array_column($hashIsNotInSource, 'remote_id');

        $needToUpdate = $needToAdd = $needToRemove = $missingHash = $removeHash = [];

        $locals = \eZDB::instance()->arrayQuery(
            "select * from ocinstall_tags where path_string like $pathStringLikeSql"
        );

        if (count($locals)) {
            $localHashes = array_column($locals, 'hash');
            $missingHash = array_diff($sourceHashes, $localHashes);
            $removeHash = array_diff($localHashes, $sourceHashes);
        }

        $needToUpdateRemoteIdList = [];
        foreach ($source as $item) {
            if (in_array($item['remote_id'], $hashIsNotInSourceRemoteIdList)) {
                $needToUpdate[] = $item;
                $needToUpdateRemoteIdList[] = $item['remote_id'];
            } else {
                if (in_array($item['hash'], $missingHash)) {
                    $needToAdd[] = $item;
                }
            }
        }
        if (count($removeHash)) {
            $sql = "select * from ocinstall_tags
                        where path_string like $pathStringLikeSql
                          and hash in ('" . implode("','", $removeHash) . "')";
            if (count($needToUpdateRemoteIdList)) {
                $sql .= " and remote_id not in ('" . implode("','", $needToUpdateRemoteIdList) . "')";
            }
            $needToRemove = \eZDB::instance()->arrayQuery($sql);
        }

        if (!$this->async) {
            if (count($needToAdd)) {
                $this->logger->debug(' - add tags ' . implode(', ', array_column($needToAdd, 'keyword_it')));
            }
            if (count($needToUpdate)) {
                $this->logger->debug(' - update tags ' . implode(', ', array_column($needToUpdate, 'keyword_it')));
            }
            if (count($needToRemove)) {
                $this->logger->warning(
                    ' - found obsolete tags ' . implode(', ', array_column($needToRemove, 'keyword_it'))
                );
            }
        }

        if ($doUpdate) {
            array_unshift($source, $sourceRoot);
            foreach ($needToAdd as $item) {
                $parentTag = $this->findParent($item, $source);
                if (!$this->async) $this->logger->notice('   + ' . $item['keyword_it']);
                $this->createTag($item, $parentTag);
            }
            foreach ($needToUpdate as $item) {
                $parentTag = $this->findParent($item, $source);
                if (!$this->async) $this->logger->warning('   * ' . $item['keyword_it']);
                $this->updateTag($item, $parentTag);
            }
            foreach ($needToRemove as $item) {
                $parentTag = $this->findParent($item, $source);
//                if (!$this->async) $this->logger->error('   - ' . $item['keyword_it']);
                $this->removeTag($item, $parentTag);
            }
        }
    }

    private function findParent($needle, $stack)
    {
        foreach ($stack as $item) {
            if ($item['id'] == $needle['parent_id']) {
                $parent = \eZTagsObject::fetchByRemoteID($item['remote_id']);
                if ($parent instanceof \eZTagsObject) {
                    return $parent;
                }
            }
        }

        return false;
    }

    /**
     * @param array $sourceRow
     *
     * @return \Opencontent\Opendata\Api\Values\Tag
     * @throws Exception
     */
    private function createTag($sourceRow, $parentTag = null)
    {
        $parentTagId = $parentTag instanceof \eZTagsObject ? (int)$parentTag->attribute('id') : 0;
        if ($this->async) {
            \eZDebug::writeError('Create tag ' . $sourceRow['keyword_it'], 'Installer');
        }
        $struct = new TagStruct();
        $struct->parentTagId = $parentTagId;
        $struct->keyword = $sourceRow['keyword_it'];
        $struct->locale = 'ita-IT';
        $struct->alwaysAvailable = true;
        $result = $this->tagRepository->create($struct);

        /** @var Tag $tag */
        $tag = $result['tag'];
        $this->setTagTranslationsAndSynonyms($tag, $sourceRow);

        $tagObj = \eZTagsObject::fetch((int)$tag->id);
        $tagObj->setAttribute('remote_id', $sourceRow['remote_id']);
        $tagObj->store();

        self::refreshTagList();
        return $tag;
    }

    private function updateTag($sourceRow, $parentTag = null)
    {
        $tag = \eZTagsObject::fetchByRemoteID($sourceRow['remote_id']);
        if ($tag instanceof \eZTagsObject) {
            $tagValue = $this->tagRepository->read((int)$tag->attribute('id'), 0, 0);
            $this->setTagTranslationsAndSynonyms($tagValue, $sourceRow);
        }
    }

    private function removeTag($sourceRow, $parentTag = null)
    {
        //@todo
    }

    private function setTagTranslationsAndSynonyms(Tag $tag, $sourceRow)
    {
        foreach (
            [
                'keyword_it' => 'ita-IT',
                'keyword_de' => 'ger-DE',
                'keyword_pa' => 'ita-PA',
                'keyword_en' => 'eng-GB',
            ] as $field => $locale
        ) {
            if (!empty($sourceRow[$field])) {
                $translationStruct = new TagTranslationStruct();
                $translationStruct->forceUpdate = true;
                $translationStruct->tagId = $tag->id;
                $translationStruct->keyword = trim($sourceRow[$field]);
                $translationStruct->locale = $locale;
                $this->tagRepository->addTranslation($translationStruct);
//            } else {
//                \eZTagsKeyword::removeObject(
//                    \eZTagsKeyword::definition(),
//                    null, [
//                        'keyword_id' => $tag->id,
//                        'locale' => $locale
//                    ]
//                );
            }
        }

        foreach (
            [
                'synonyms_it' => 'ita-IT',
                'synonyms_de' => 'ger-DE',
                'synonyms_pa' => 'ita-PA',
                'synonyms_en' => 'eng-GB',
            ] as $field => $locale
        ) {
            if (!empty($sourceRow[$field])) {
                $synonyms = explode(';', $sourceRow[$field]);
                foreach ($synonyms as $synonym) {
                    $synonymStruct = new TagSynonymStruct();
                    $synonymStruct->tagId = $tag->id;
                    $synonymStruct->keyword = trim($synonym);
                    $synonymStruct->locale = $locale;
                    $this->tagRepository->addSynonym($synonymStruct);
                }
            }
        }
    }
}