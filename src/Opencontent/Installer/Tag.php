<?php

namespace Opencontent\Installer;

use Exception;
use eZTagsKeyword;
use eZTagsObject;
use Opencontent\Opendata\Api\Structs\TagSynonymStruct;
use Opencontent\Opendata\Api\TagRepository;

class Tag extends TagTree
{
    private $deprecatedTagRoot;

    public function dryRun()
    {
        $type = $this->step['type'];

        if ($type === 'rename_tag'){
            $tagId = $this->installerVars->parseVarValue($this->step['tag']);
            if ($tagId > 0){
                $keywords = $this->step['keywords'];
                $this->logger->info("Rename tag $tagId in " . implode(', ', $keywords));
            }

        }else {
            $parentTagId = $this->installerVars->parseVarValue($this->step['parent']);
            $parentTag = eZTagsObject::fetch((int)$parentTagId);
            if (!$parentTag instanceof eZTagsObject) {
                throw new Exception("Parent tag $parentTagId not found");
            }
            $tags = $this->step['tags'];
            $tagKeywords = array_column($tags, 'keyword');
            $action = '';
            $parent = '';
            switch ($type) {
                case 'add_tag':
                    $action = 'Add tag';
                    $parentAction = 'in ' . $parentTag->attribute('keyword');
                    break;
                case 'remove_tag':
                    $action = 'Remove tag';
                    $parentAction = 'from ' . $parentTag->attribute('keyword');
                    break;
                case 'move_tag':
                    $action = 'Move tag';
                    $newParentTagId = $this->installerVars->parseVarValue($this->step['new_parent']);
                    $newParentTag = eZTagsObject::fetch((int)$newParentTagId);
                    if (!$newParentTag instanceof eZTagsObject) {
                        throw new Exception("Parent tag $newParentTagId not found");
                    }
                    $parentAction = 'from ' . $parentTag->attribute('keyword') . ' to ' . $newParentTag->attribute('keyword');
                    break;
                default:
                    throw new Exception("Action $type not handled");
            }

            $this->logger->info($action . ' ' . implode(', ', $tagKeywords) . ' ' . $parentAction);
        }
    }

    public function install()
    {
        $type = $this->step['type'];

        if ($type === 'rename_tag') {
            $tagId = $this->installerVars->parseVarValue($this->step['tag']);
            if ($tagId > 0) {
                $tag = eZTagsObject::fetch((int)$tagId);
                $keywords = $this->step['keywords'];
                if ($tag instanceof eZTagsObject && !$tag->isSynonym() && count($keywords) > 0){
                    $this->logger->info("Rename tag $tagId in " . implode(', ', $keywords));
                    $currentKeywords = $tag->getTranslations();
                    foreach ($keywords as $locale => $keyword){
                        $tagTranslation = eZTagsKeyword::fetch($tag->attribute('id'), $locale, true);
                        if ($tagTranslation instanceof eZTagsKeyword) {
                            $tagTranslation->setAttribute('keyword', $keyword);
                            $tagTranslation->store();
                        }
                    }
                    $tagRepository = new TagRepository();
                    foreach ($currentKeywords as $currentKeyword){
                        $synonymStruct = new TagSynonymStruct();
                        $synonymStruct->tagId = $tag->attribute('id');
                        $synonymStruct->keyword = $currentKeyword->attribute('keyword');
                        $synonymStruct->locale = $currentKeyword->attribute('locale');
                        $tagRepository->addSynonym($synonymStruct);
                    }
                }
            }

        }else {
            $parentTagId = $this->installerVars->parseVarValue($this->step['parent']);
            $parentTag = eZTagsObject::fetch((int)$parentTagId);
            if (!$parentTag instanceof eZTagsObject) {
                throw new Exception("Parent tag $parentTagId not found");
            }
            $tags = $this->step['tags'];

            switch ($type) {
                case 'add_tag':
                    foreach ($tags as $tagItem) {
                        $tag = $this->createTag(0, $tagItem, $parentTagId);
                        $this->installerVars['tag_' . $this->step['identifier']] = $tag->id;
                        $this->logger->info('Add tag ' . $tagItem['keyword'] . ' to ' . $parentTag->attribute('keyword'));
                    }
                    break;
                case 'remove_tag':
                    $children = $parentTag->getChildren();
                    foreach ($tags as $tagItem) {
                        foreach ($children as $child) {
                            if ($child->attribute('keyword') == $tagItem['keyword']) {
                                $this->moveTag($child, $tagItem['locale'], $tagItem['alwaysAvailable'], $this->getDeprecatedTagRoot());
                                $this->logger->info('Remove tag ' . $tagItem['keyword'] . ' from ' . $parentTag->attribute('keyword'));
                                break;
                            }
                        }
                    }
                    break;
                case 'move_tag':
                    $newParentTagId = $this->installerVars->parseVarValue($this->step['new_parent']);
                    $newParentTag = eZTagsObject::fetch((int)$newParentTagId);
                    if (!$newParentTag instanceof eZTagsObject) {
                        throw new Exception("Parent tag $newParentTagId not found");
                    }
                    $children = $parentTag->getChildren();
                    foreach ($tags as $tagItem) {
                        foreach ($children as $child) {
                            if ($child->attribute('keyword') == $tagItem['keyword']) {
                                $this->moveTag($child, $tagItem['locale'], $tagItem['alwaysAvailable'], $newParentTag);
                                $this->logger->info('Move tag ' . $tagItem['keyword'] . ' from ' . $parentTag->attribute('keyword') . ' to ' . $newParentTag->attribute('keyword'));
                                break;
                            }
                        }
                    }
                    break;
                default:
                    throw new Exception("Action $type not handled");
            }
        }
    }

    private function getDeprecatedTagRoot()
    {
        if ($this->deprecatedTagRoot === null) {
            $this->deprecatedTagRoot = eZTagsObject::fetchByUrl('Classificazioni deprecate');
            if (!$this->deprecatedTagRoot instanceof eZTagsObject) {
                $deprecatedTagRoot = $this->createTag(0, [
                    'keyword' => 'Classificazioni deprecate',
                    'locale' => 'ita-IT',
                    'alwaysAvailable' => true,
                    'synonyms' => [],
                    'keywordTranslations' => ['ita-IT' => 'Classificazioni deprecate']
                ], 0);
                $this->deprecatedTagRoot = eZTagsObject::fetchByUrl('Classificazioni deprecate');
            }

            if (!$this->deprecatedTagRoot instanceof eZTagsObject) {
                throw new Exception("Error creating Deprecated tag root");
            }
        }

        return $this->deprecatedTagRoot;
    }

    private function moveTag(eZTagsObject $tag, $locale, $alwaysAvailable, eZTagsObject $newParentTag)
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
                if ($oldParentTag instanceof eZTagsObject)
                    $oldParentTag->updateModified();

                $synonyms = $tag->getSynonyms(true);
                foreach ($synonyms as $synonym) {
                    $synonym->setAttribute('parent_id', $newParentID);
                    $synonym->store();
                }

                $updatePathString = true;
            }

            $tagTranslation = eZTagsKeyword::fetch($tag->attribute('id'), $locale, true);
            $tagTranslation->setAttribute('keyword', $newKeyword);
            $tagTranslation->setAttribute('status', eZTagsKeyword::STATUS_PUBLISHED);
            $tagTranslation->store();
            $tag->updateMainTranslation($locale);
            $tag->setAlwaysAvailable($alwaysAvailable);

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
        } else {
            $this->logger->debug(" |- tag $newKeyword already exists in " . $newParentTag->attribute('keyword'));
        }
    }
}