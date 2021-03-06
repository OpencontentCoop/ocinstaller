<?php

namespace Opencontent\Installer;

use Opencontent\Opendata\Api\Structs\TagStruct;
use Opencontent\Opendata\Api\Structs\TagSynonymStruct;
use Opencontent\Opendata\Api\Structs\TagTranslationStruct;
use Opencontent\Opendata\Api\TagRepository;
use Exception;
use Opencontent\Opendata\Api\Values\Tag;

class TagTree extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $remoteHost;

    private $rootTag;

    public function dryRun()
    {
        if (isset($this->step['source'])) {
            $remoteUrl = $this->step['source'];
            $parts = explode('/api/opendata/v2/tags_tree/', $remoteUrl);
            $rootTag = array_pop($parts);
            $this->logger->info("Install tag tree " . $rootTag);
        }else{
            $this->logger->info("Install tag tree " . $this->step['identifier']);
        }
        $this->installerVars['tagtree_' . $rootTag] = 0;
    }

    /**
     * @throws Exception
     */
    public function install()
    {
        if (isset($this->step['source'])){
            $this->installFromRemote();
        }else{
            $this->installFromLocal();
        }
    }

    /**
     * @throws Exception
     */
    private function installFromRemote()
    {
        $remoteUrl = $this->step['source'];
        $parts = explode('/api/opendata/v2/tags_tree/', $remoteUrl);
        $this->remoteHost = array_shift($parts);
        $this->rootTag = array_pop($parts);

        $this->logger->info("Install tag tree " . $this->rootTag . " from " . $this->remoteHost);

        $client = new TagClient(
            $this->remoteHost,
            null,
            null,
            'tags_tree'
        );
        $remoteRoot = $client->readTag($this->rootTag);
        $tag = $this->recursiveCreateTag($remoteRoot, 0);

        $this->installerVars['tagtree_' . $this->rootTag] = $tag->id;
    }

    /**
     * @throws Exception
     */
    private function installFromLocal()
    {
        $identifier = $this->step['identifier'];
        $this->logger->info("Install tag tree " . $identifier . " from " . "tagtree/{$identifier}");
        $remoteRoot = $this->ioTools->getJsonContents("tagtree/{$identifier}.yml");
        $tag = $this->recursiveCreateTag($remoteRoot, 0);

        $this->installerVars['tagtree_' . $remoteRoot['keyword']] = $tag->id;
    }

    /**
     * @param int $recursionLevel
     * @param $remoteTag
     * @param $parentTagId
     * @param string $locale
     *
     * @return \Opencontent\Opendata\Api\Values\Tag
     * @throws Exception
     */
    protected function createTag($recursionLevel, $remoteTag, $parentTagId, $locale = 'ita-IT')
    {
        $tagRepository = new TagRepository();

        $struct = new TagStruct();
        $struct->parentTagId = $parentTagId;
        $struct->keyword = $remoteTag['keyword'];
        $struct->locale = $locale;
        $struct->alwaysAvailable = true;

        $result = $tagRepository->create($struct);
        $isNew = $result['message'] == 'success' ? ' + ' : '';
        $this->logger->debug(str_pad('', $recursionLevel, '  ', STR_PAD_LEFT) . ' |- ' . $isNew . $remoteTag['keyword']);
        /** @var Tag $tag */
        $tag = $result['tag'];

        if (isset($remoteTag['synonyms']) && count($remoteTag['synonyms']) > 0){
            foreach ($remoteTag['synonyms'] as $locale => $synonym){
                if (!is_array($synonym)){
                    $synonym = [$synonym];
                }
                foreach ($synonym as $syn) {
                    $synonymStruct = new TagSynonymStruct();
                    $synonymStruct->tagId = $tag->id;
                    $synonymStruct->keyword = $syn;
                    $synonymStruct->locale = $locale;

                    $result = $tagRepository->addSynonym($synonymStruct);
                    $isNew = $result['message'] == 'success' ? ' + ' : '';
                    $this->logger->debug(str_pad('', $recursionLevel, '  ', STR_PAD_LEFT) . '     |- ' . $isNew . $syn);
                }
            }
        }

        foreach ($remoteTag['keywordTranslations'] as $locale => $translation){
            if ($locale != $struct->locale) {
                $translationStruct = new TagTranslationStruct();
                $translationStruct->tagId = $tag->id;
                $translationStruct->keyword = $translation;
                $translationStruct->locale = $locale;

                $result = $tagRepository->addTranslation($translationStruct);
                $isNew = $result['message'] == 'success' ? ' + ' : '';
                $this->logger->debug(str_pad('', $recursionLevel, '  ', STR_PAD_LEFT) . '     |- ' . $isNew . $translation);
            }
        }

        return $tag;
    }

    /**
     * @param $remoteTag
     * @param int $localeParentTagLocation
     * @param int $recursionLevel
     * @return \Opencontent\Opendata\Api\Values\Tag
     * @throws Exception
     */
    private function recursiveCreateTag($remoteTag, $localeParentTagLocation = 0, $recursionLevel = 0)
    {
        $tag = $this->createTag($recursionLevel, $remoteTag, $localeParentTagLocation);
        if ($remoteTag['hasChildren']) {
            foreach ($remoteTag['children'] as $remoteTagChild) {
                ++$recursionLevel;
                $this->recursiveCreateTag($remoteTagChild, $tag->id, $recursionLevel);
                --$recursionLevel;
            }
        }

        return $tag;
    }
}

