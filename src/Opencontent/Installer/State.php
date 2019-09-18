<?php

namespace Opencontent\Installer;

use eZContentObjectStateGroup;
use eZContentObjectStateLanguage;
use eZContentLanguage;
use Exception;
use eZContentObjectState;
use OCOpenDataStateRepositoryCache;

class State extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $stateDefinition;

    public function __construct($step)
    {
        $identifier = $step['identifier'];
        $stateDefinition = $this->ioTools->getJsonContents("states/{$identifier}.yml");
        $this->stateDefinition = $stateDefinition;
    }

    /**
     * @throws Exception
     */
    public function install()
    {
        $groupIdentifier = $this->stateDefinition['group_identifier'];
        $groupNames = $this->stateDefinition['group_name'];
        $states = $this->stateDefinition['states'];

        $this->logger->log("Create state group " . $this->stateDefinition['group_identifier']);

        $stateGroup = eZContentObjectStateGroup::fetchByIdentifier($groupIdentifier);
        if (!$stateGroup instanceof eZContentObjectStateGroup) {
            $stateGroup = new eZContentObjectStateGroup();
            $stateGroup->setAttribute('identifier', $groupIdentifier);
            $stateGroup->setAttribute('default_language_id', 2);

            /** @var eZContentObjectStateLanguage[] $translations */
            $translations = $stateGroup->allTranslations();
            foreach ($translations as $translation) {
                /** @var eZContentLanguage $language */
                $language = eZContentLanguage::fetch($translation->attribute('real_language_id'));
                if (isset($groupNames[$language->attribute('locale')])) {
                    $translation->setAttribute('name', $groupNames[$language->attribute('locale')]);
                    $translation->setAttribute('description', $groupNames[$language->attribute('locale')]);
                } else {
                    $translation->setAttribute('name', $groupNames['eng-GB']);
                    $translation->setAttribute('description', $groupNames['eng-GB']);
                }
            }

            $messages = array();
            $isValid = $stateGroup->isValid($messages);
            if (!$isValid) {
                throw new Exception(implode(',', $messages));
            }
            $stateGroup->store();
        }

        foreach ($states as $state) {
            $stateObject = $stateGroup->stateByIdentifier($state['identifier']);
            if (!$stateObject instanceof eZContentObjectState) {
                $stateObject = $stateGroup->newState($state['identifier']);
                $stateObject->setAttribute('default_language_id', 2);

                /** @var eZContentObjectStateLanguage[] $stateTranslations */
                $stateTranslations = $stateObject->allTranslations();

                foreach ($stateTranslations as $translation) {
                    $language = eZContentLanguage::fetch($translation->attribute('language_id'));
                    if (isset($state['name'][$language->attribute('locale')])) {
                        $translation->setAttribute('name', $state['name'][$language->attribute('locale')]);
                        $translation->setAttribute('description', $state['name'][$language->attribute('locale')]);
                    } else {
                        $translation->setAttribute('name', $state['name']['eng-GB']);
                        $translation->setAttribute('description', $state['name']['eng-GB']);
                    }
                }
                $messages = array();
                $isValid = $stateObject->isValid($messages);
                if (!$isValid) {
                    throw new Exception(implode(',', $messages));
                }
                $stateObject->store();
            }
        }

        OCOpenDataStateRepositoryCache::clearCache();
    }
}