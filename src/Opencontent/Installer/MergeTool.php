<?php

namespace Opencontent\Installer;

use eZContentObject;
use eZContentObjectTreeNode;
use eZOperationHandler;
use eZFunctionHandler;
use eZContentObjectTreeNodeOperations;
use eZContentCacheManager;
use eZSolr;
use eZDB;

class MergeTool
{
    protected $masterNode;

    protected $slaveNode;

    protected $translationMap;

    function __construct(int $masterNodeId, int $slaveNodeId, array $translationMap = null)
    {
        $this->masterNode = eZContentObjectTreeNode::fetch($masterNodeId);
        $this->slaveNode = eZContentObjectTreeNode::fetch($slaveNodeId);
        $this->translationMap = $translationMap;

        $this->sanityCheck();
    }

    protected function sanityCheck()
    {
        if (!$this->masterNode instanceof eZContentObjectTreeNode) {
            throw new Exception('Fail fetching master node');
        }

        if (!$this->slaveNode instanceof eZContentObjectTreeNode) {
            throw new Exception('Fail fetching slave node');
        }

        if ($this->masterNode->attribute('object')->attribute('contentclass_id')
            != $this->slaveNode->attribute('object')->attribute('contentclass_id')) {
            throw new Exception('Different class objects');
        }

        if (!is_array($this->translationMap)) {
            $languageList = $this->masterNode->attribute('object')->attribute('available_languages');
            foreach ($languageList as $language) {
                $this->translationMap[$language] = $this->masterNode->attribute('node_id');
            }
        }
    }

    public function run()
    {
        $db = eZDB::instance();
        $db->begin();
        $this->mergeLocations();
        $this->mergeTranslations();
        $this->updateReverseRelatedObjects();
        $this->mergeChildren();
        $this->purgeSlave();
        $db->commit();
    }

    protected function mergeLocations()
    {
        $operationResult = eZOperationHandler::execute(
            'content',
            'addlocation',
            [
                'node_id' => $this->masterNode->attribute('node_id'),
                'object_id' => $this->masterNode->attribute('object')->attribute('id'),
                'select_node_id_array' => $this->slaveNode->attribute('object')->attribute('parent_nodes'),
            ],
            null,
            true
        );
    }

    protected function mergeTranslations()
    {
        foreach ($this->translationMap as $language => $nodeID) {
            $this->doContentObjectMerge(
                $this->masterNode->attribute('object'),
                $this->slaveNode->attribute('object'),
                $language,
                $this->masterNode->attribute('node_id') == $nodeID
            );
        }
    }

    protected function updateReverseRelatedObjects()
    {
        $slaveObjectID = $this->slaveNode->attribute('object')->attribute('id');
        $masterObjectID = $this->masterNode->attribute('object')->attribute('id');

        $reverseRelatedList = eZFunctionHandler::execute(
            'content', 'reverse_related_objects',
            [
                'object_id' => $slaveObjectID,
                'all_relations' => true,
                'group_by_attribute' => true,
                'as_object' => true,
            ]
        );

        foreach ($reverseRelatedList as $id => $reverseRelatedSubList) {
            foreach ($reverseRelatedSubList as $reverseRelatedObject) {
                if ($masterObjectID != $reverseRelatedObject->attribute('id')) {
                    // To get the different languages of the related object, we need to go through a node fetch
                    $mainNodeID = $reverseRelatedObject->attribute('main_node_id');
                    $languageList = $reverseRelatedObject->attribute('available_languages');
                    foreach ($languageList as $language) {
                        $tmpNode = eZFunctionHandler::execute('content', 'node', [
                            'node_id' => $mainNodeID,
                            'language_code' => $language,
                        ]);
                        $reverseRelatedObject = $tmpNode->attribute('object');
                        $newVersion = $reverseRelatedObject->createNewVersionIn($language);
                        $newVersion->setAttribute('modified', time());
                        $newVersion->store();

                        $newAttributes = $newVersion->contentObjectAttributes();
                        foreach ($newAttributes as $reverseAttribute) {
                            if (empty($id) or $reverseAttribute->attribute('contentclassattribute_id') == $id) {
                                switch ($reverseAttribute->attribute('data_type_string')) {
                                    case 'ezobjectrelationlist':
                                        $oldList = $reverseAttribute->toString();
                                        $list = explode('-', $oldList);
                                        foreach ($list as $key => $objectID) {
                                            if ($objectID == $slaveObjectID) {
                                                $list[$key] = $masterObjectID;
                                            }
                                        }
                                        $list = implode('-', array_unique($list));
                                        if ($oldList != $list) {
                                            $reverseAttribute->fromString($list);
                                            $reverseAttribute->store();
                                        }
                                        break;

                                    case 'ezobjectrelation':
                                        $oldRelation = $reverseAttribute->toString();
                                        if ($oldRelation != $masterObjectID) {
                                            $reverseAttribute->fromString($masterObjectID);
                                            $reverseAttribute->store();
                                        }
                                        break;

                                    case 'ezxmltext':
                                        $oldXml = $reverseAttribute->toString();
                                        $xml = $oldXml;
                                        $xml = str_ireplace(
                                            "object_id=\"$slaveObjectID\"",
                                            "object_id=\"$masterObjectID\"",
                                            $xml
                                        );
                                        $relatedNodeArray = $this->slaveNode->attribute('object')->attribute(
                                            'assigned_nodes'
                                        );
                                        $newRelatedNodeID = $this->masterNode->attribute('object')->attribute(
                                            'main_node_id'
                                        );
                                        foreach ($relatedNodeArray as $relatedNode) {
                                            $relatedNodeID = $relatedNode->attribute('node_id');
                                            $xml = str_ireplace(
                                                "node_id=\"$relatedNodeID\"",
                                                "node_id=\"$newRelatedNodeID\"",
                                                $xml
                                            );
                                        }
                                        if ($xml != $oldXml) {
                                            $reverseAttribute->fromString($xml);
                                            $reverseAttribute->store();
                                        }
                                        break;
                                    default:
                                }
                            }
                        }
                        $operationResult = eZOperationHandler::execute('content', 'publish', [
                            'object_id' => $reverseRelatedObject->attribute('id'),
                            'version' => $newVersion->attribute('version'),
                        ]);
                        eZContentCacheManager::clearObjectViewCache(
                            $reverseRelatedObject->attribute('id'),
                            $newVersion->attribute('version')
                        );
                    }
                }
            }
        }
    }

    protected function mergeChildren()
    {
        foreach ($this->slaveNode->attribute('object')->attribute('assigned_nodes') as $node) {
            foreach ($node->attribute('children') as $child) {
                eZContentObjectTreeNodeOperations::move(
                    $child->attribute('node_id'),
                    $this->masterNode->attribute('node_id')
                );
            }
        }
    }

    protected function purgeSlave()
    {
        $this->slaveNode->attribute('object')->purge();
    }

    protected function doContentObjectMerge(
        eZContentObject $master,
        eZContentObject $slave,
        $language,
        $useMasterValues
    ) {
        $newMasterVersion = $master->createNewVersionIn($language);
        $newMasterVersion->setAttribute('modified', time());
        $newMasterVersion->store();

        $newMasterAttributes = $newMasterVersion->contentObjectAttributes($language);

        $slaveDataMap = $slave->fetchDataMap(false, $language);

        $relations = [];

        foreach ($newMasterAttributes as $attribute) {
            $identifier = $attribute->attribute('contentclass_attribute_identifier');
            if (isset($slaveDataMap[$identifier])) {
                switch ($attribute->attribute('data_type_string')) {
                    case 'ezobjectrelationlist':
                        {
                            $masterList = $attribute->attribute('has_content') ?
                                explode('-', $attribute->toString()) : [];
                            $slaveList = $slaveDataMap[$identifier]->attribute('has_content') ? 
                                explode('-', $slaveDataMap[$identifier]->toString()) : [];
                            $relations = array_unique(array_merge($masterList, $slaveList));
                            $relations = $this->avoidSelfRelation($relations);
                            $attribute->fromString(implode('-', $relations));
                            $attribute->store();
                        }
                        break;
                    case 'ezkeyword':
                        {
                            $masterList = $attribute->attribute('has_content') ? 
                                explode(',', $attribute->toString()) : [];
                            $slaveList = $slaveDataMap[$identifier]->attribute('has_content') ? 
                                explode(',', $slaveDataMap[$identifier]->toString()) : [];
                            $tags = array_unique(array_merge($masterList, $slaveList));
                            $attribute->fromString(implode(',', $tags));
                            $attribute->store();
                        }
                        break;
                    default:
                        {
                            if (!$useMasterValues) {
                                $value = $slaveDataMap[$identifier]->toString();
                                $attribute->fromString($value);
                                $attribute->store();
                            }
                        }
                        break;
                }
            }
        }

        $operationResult = eZOperationHandler::execute('content', 'publish', [
            'object_id' => $master->attribute('id'),
            'version' => $newMasterVersion->attribute('version'),
        ]);
        eZContentCacheManager::clearObjectViewCache($master->attribute('id'), $newMasterVersion->attribute('version'));

        if (class_exists('eZSolr')) {
            // @todo use ezpSearchEgine
            $search = new eZSolr();
            foreach ($relations as $relation) {
                $search->addObject(eZContentObject::fetch($relation), true);
            }
            eZContentObject::clearCache($relations);
        }
    }

    protected function avoidSelfRelation($relations)
    {
        $return = [];
        foreach ($relations as $relation) {
            if ($relation != $this->masterNode->attribute('object')->attribute('id')) {
                $return[] = $relation;
            }
        }
        return $return;
    }

}