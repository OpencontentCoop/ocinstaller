<?php

namespace Opencontent\Installer\TagTreeCsv;

use Psr\Log\LoggerAwareTrait;
use eZTagsObject;
use eZTagsKeyword;
use Opencontent\Opendata\Api\Structs\TagStruct;
use Opencontent\Opendata\Api\Structs\TagSynonymStruct;
use Opencontent\Opendata\Api\Structs\TagTranslationStruct;
use Opencontent\Opendata\Api\TagRepository;
use Opencontent\Opendata\Api\Values\Tag;
use eZDB;

class Updater
{
    use LoggerAwareTrait;

    private $languages;

    private $files;

    private $rootTag;

    /**
     * @var TagRepository
     */
    private $tagRepository;

    private $deprecatedTagRoot;

    private $dryRun = false;

    private $removeTranslation = false;

    private $waitForUser = false;

    private $moveInDeprecated = false;

    public function __construct(array $languages, array $files)
    {
        $this->languages = $languages;
        $this->files = $files;
        $this->tagRepository = new TagRepository();
        $this->deprecatedTagRoot = \Opencontent\Installer\Tag::fetchDeprecatedTagRoot();
    }

    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    public function setRemoveTranslation(bool $removeTranslation): void
    {
        $this->removeTranslation = $removeTranslation;
    }

    public function setMoveInDeprecated(bool $moveInDeprecated): void
    {
        $this->moveInDeprecated = $moveInDeprecated;
    }

    public function run()
    {
        foreach ($this->files as $index => $file) {
            if ($this->waitForUser) {
                \ezcConsoleDialogViewer::displayDialog(
                    \ezcConsoleQuestionDialog::YesNoQuestion(
                        new \ezcConsoleOutput(),
                        $file,
                        'y'
                    )
                );
            }

            $csv = new Csv($file, $this->languages);
            $csvTree = $csv->getTree();
            $this->rootTag = $csvTree->getRoot()->getTagObject();

            $projection = (new Projection($this->languages))->refresh();
            if ($this->logger) {
                $this->logger->debug(sprintf('Search diff with %s', $file));
            }
            $localDiffTree = $projection->getTreeDiffByParentId(
                (int)$this->rootTag->attribute('id'),
                $csvTree
            );
            if ($this->logger) {
                $this->logger->info(sprintf('Found %s diff in file %s', $localDiffTree->count(), $file));
            }
            $reparentList = [];
            foreach ($localDiffTree->getItems() as $item) {
                $path = $item->getPath();
                $sourceItem = $csvTree->findSimilar($item, 'it');
                $localTag = $item->findTagObject($this->rootTag);
                if ($sourceItem) {
                    $sourcePath = $sourceItem->getPath();
                    if (!$localTag instanceof eZTagsObject) {
                        if ($this->logger) {
                            $this->logger->notice(' + ' . $path);
                        }
                        if (!$this->dryRun) {
                            $this->createTag($item);
                        }
                    } else {
                        $diff = $item->diff($sourceItem);
                        if (strpos($diff, '~p') !== false) {
                            $reparentList[] = [$localTag, $sourceItem, $diff, $path];
                        } else {
                            if ($this->logger) {
                                $this->logger->warning(' ~ ' . $path . ' ' . $diff);
                            }
                            if (!$this->dryRun) {
                                $this->updateTag($localTag, $sourceItem);
                            }
                        }
                    }
                } else {
                    if ($this->logger) {
                        $this->logger->error(
                            ($this->moveInDeprecated) ?
                                ' - ' . $path :
                                ' * ' . $path
                        );
                    }
                    if (!$this->dryRun) {
                        $this->removeTag($localTag, $item);
                    }
                }
            }
            foreach ($reparentList as $reparent) {
                if ($this->logger) {
                    $this->logger->warning(' ~> ' . $reparent[3] . ' ' . $reparent[2]);
                }
                if (!$this->dryRun) {
                    $this->updateTag($reparent[0], $reparent[1], true);
                }
            }
        }
    }

    private function createTag(TagTreeItem $item): Tag
    {
        $parentTag = $item->parentId > 0 ? $item->findParentTagObject($this->rootTag) : $this->rootTag;
        $parentTagId = $parentTag instanceof eZTagsObject ? (int)$parentTag->attribute('id') : 0;

        $struct = new TagStruct();
        $struct->parentTagId = $parentTagId;
        $struct->keyword = $item->keywords['it'];
        $struct->locale = 'ita-IT';
        $struct->alwaysAvailable = true;
        $result = $this->tagRepository->create($struct);
        if ($this->logger) {
            $this->logger->debug(
                ' - add #' . $item->keywords['it'] . ' ==> ' . $result['message'] . ' ' . $result['tag']->id
            );
        }
        /** @var Tag $tag */
        $tag = $result['tag'];
        $tagsObject = eZTagsObject::fetch((int)$tag->id);
        $tagsObject->setAttribute('remote_id', $item->remoteId);
        $tagsObject->store();

        $this->setTagTranslationsAndSynonyms($tagsObject, $tag, $item);
        $this->setTagDescriptions($tagsObject, $item);
        return $tag;
    }

    private function updateTag(eZTagsObject $tag, TagTreeItem $item, $reparent = false)
    {
        $tagValue = $this->tagRepository->read((int)$tag->attribute('id'), 0, 0);
        $this->setTagTranslationsAndSynonyms($tag, $tagValue, $item);
        $this->setTagDescriptions($tag, $item);
        if ($reparent) {
            $parentTag = $item->findParentTagObject($this->rootTag);
            $this->moveTag($tag, $parentTag);
        }
    }

    private function removeTag(eZTagsObject $tag, TagTreeItem $item)
    {
        if ($this->moveInDeprecated) {
            $this->moveTag($tag, $this->deprecatedTagRoot);
            if ($this->logger) {
                $this->logger->debug(' - remove #' . $item->keywords['it']);
            }
        }
    }

    private function setTagTranslationsAndSynonyms(eZTagsObject $tagsObject, Tag $tag, TagTreeItem $item): void
    {
        foreach ($this->languages as $locale => $code) {
            if (!empty($item->keywords[$code])) {
                $translationStruct = new TagTranslationStruct();
                $translationStruct->forceUpdate = true;
                $translationStruct->tagId = $tag->id;
                $translationStruct->keyword = trim($item->keywords[$code]);
                $translationStruct->locale = $locale;
                $translationStruct->forceUpdate = true;
                $this->tagRepository->addTranslation($translationStruct);
            } else {
                $translation = $tagsObject->translationByLocale($locale);
                $mainTranslation = $tagsObject->getMainTranslation();
                if ($this->removeTranslation
                    && $translation instanceof eZTagsKeyword
                    && $translation->attribute('locale') != $mainTranslation->attribute('locale')) {
                    $translation->remove();
                }
            }
        }

        foreach ($this->languages as $locale => $code) {
            $mainTagsObject = eZTagsObject::fetchWithMainTranslation($tag->id);
            $currentSynonyms = $mainTagsObject->getSynonyms($locale);
            if (!empty($item->synonyms[$code])) {
                $synonyms = explode(';', $item->synonyms[$code]);
                foreach ($synonyms as $synonym) {
                    $synonymStruct = new TagSynonymStruct();
                    $synonymStruct->tagId = $tag->id;
                    $synonymStruct->keyword = trim($synonym);
                    $synonymStruct->locale = $locale;
                    $this->tagRepository->addSynonym($synonymStruct);
                }
                foreach ($currentSynonyms as $synonym) {
                    if ($synonym->attribute('current_language') == $locale
                        && !in_array($synonym->attribute('keyword'), $synonyms)) {
                        $this->removeSynonym($synonym);
                    }
                }
            } else {
                foreach ($currentSynonyms as $synonym) {
                    if ($synonym->attribute('current_language') == $locale) {
                        $this->removeSynonym($synonym);
                    }
                }
            }
        }
    }

    private function removeSynonym($synonym)
    {
        eZDB::instance()->begin();
        $parentTag = $synonym->getParent(true);
        if ($parentTag instanceof eZTagsObject) {
            $parentTag->updateModified();
        }
        $synonym->registerSearchObjects();
        $synonym->transferObjectsToAnotherTag($synonym->attribute('main_tag_id'));
        $synonym->remove();
        eZDB::instance()->commit();
    }

    private function setTagDescriptions(eZTagsObject $tag, TagTreeItem $item): void
    {
        foreach ($this->languages as $locale => $code) {
            $tagDescription = new \eZTagsDescription([
                'keyword_id' => (int)$tag->attribute('id'),
                'locale' => $locale,
                'description_text' => $item->descriptions[$code],
            ]);
            $tagDescription->store();
        }
    }

    private function moveTag(eZTagsObject $tag, eZTagsObject $newParentTag, $locale = 'ita-IT', $alwaysAvailable = true)
    {
        $newKeyword = $tag->attribute('keyword');
        $newParentID = $newParentTag->attribute('id');
        if (!eZTagsObject::exists($tag->attribute('id'), $newKeyword, $newParentID)) {
            $updateDepth = false;
            $updatePathString = false;

            $db = \eZDB::instance();
            $db->begin();

            $oldParentDepth = $tag->attribute('depth') - 1;
            $newParentDepth = $newParentTag instanceof eZTagsObject ? $newParentTag->attribute('depth') : 0;

            if ($oldParentDepth != $newParentDepth) {
                $updateDepth = true;
            }

            $oldParentTag = false;
            if ($tag->attribute('parent_id') != $newParentID) {
                $oldParentTag = $tag->getParent(true);
                if ($oldParentTag instanceof eZTagsObject) {
                    $oldParentTag->updateModified();
                }

                $synonyms = $tag->getSynonyms(true);
                foreach ($synonyms as $synonym) {
                    $synonym->setAttribute('parent_id', $newParentID);
                    $synonym->store();
                }

                $updatePathString = true;
            }

            $tagTranslation = eZTagsKeyword::fetch($tag->attribute('id'), $locale, true);
            if ($tagTranslation) {
                $tagTranslation->setAttribute('keyword', $newKeyword);
                $tagTranslation->setAttribute('status', eZTagsKeyword::STATUS_PUBLISHED);
                $tagTranslation->store();
                $tag->updateMainTranslation($locale);
                $tag->setAlwaysAvailable($alwaysAvailable);
            }

            $tag->setAttribute('parent_id', $newParentID);
            $tag->store();

            if ($updatePathString) {
                $tag->updatePathString();
            }

            if ($updateDepth) {
                $tag->updateDepth();
            }

            $tag->updateModified();
            $tag->registerSearchObjects();

            $db->commit();
        } elseif ($this->logger) {
            $this->logger->debug(" - tag $newKeyword already exists in " . $newParentTag->attribute('keyword'));
        }
    }
}