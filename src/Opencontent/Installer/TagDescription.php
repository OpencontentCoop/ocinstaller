<?php

namespace Opencontent\Installer;

use Opencontent\Installer\Dumper\Tool;
use eZTagsObject;
use eZTagsDescription;
use Exception;

class TagDescription extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun(): void
    {
        $identifier = $this->step['identifier'];
        $definition = $this->ioTools->getJsonContents("tagdescription/{$identifier}.yml");
        $parentTagId = $this->installerVars->parseVarValue($this->step['root']);
        $parentTag = eZTagsObject::fetch((int)$parentTagId);
        if (!$parentTag instanceof eZTagsObject) {
            throw new Exception("Parent tag $parentTagId not found");
        }
        $this->logger->info("Install $identifier tagdescription for tags child of " . $parentTag->attribute('keyword'));
    }

    public function install(): void
    {
        $identifier = $this->step['identifier'];
        $definition = $this->ioTools->getJsonContents("tagdescription/{$identifier}.yml");
        $parentTagId = $this->installerVars->parseVarValue($this->step['root']);
        $parentTag = eZTagsObject::fetch((int)$parentTagId);
        if (!$parentTag instanceof eZTagsObject) {
            throw new Exception("Parent tag $parentTagId not found");
        }

        $locale = isset($this->step['locale']) ? $this->step['locale'] : 'ita-IT';

        $this->logger->info("Install $identifier tagdescription for tags child of " . $parentTag->attribute('keyword'));

        $keywordIdList = [];
        $children = $parentTag->getChildren();
        foreach ($children as $child) {
            $keywordIdList[$child->attribute('keyword')] = $child->attribute('id');
        }

        foreach ($definition['descriptions'] as $description){
            if (isset($keywordIdList[$description['keyword']])){
                $tagDescription = new eZTagsDescription([
                    'keyword_id' => $keywordIdList[$description['keyword']],
                    'locale' => $locale,
                    'description_text' => $description['text']
                ]);
                $this->logger->debug(' -> ' .  $description['keyword']);
                $tagDescription->store();
            }else{
                throw new Exception('Keyword ' . $description['keyword'] . ' not found');
            }
        }
    }
}