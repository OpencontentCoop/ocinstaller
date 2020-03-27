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

    private $identifier;

    public function dryRun()
    {
        $identifier = $this->step['identifier'];
        $stateDefinition = $this->ioTools->getJsonContents("states/{$identifier}.yml");
        $this->logger->info("Install state group " . $stateDefinition['group_identifier']);
        foreach ($stateDefinition['states'] as $state) {
            $this->installerVars['state_' . $stateDefinition['group_identifier'] . '_' . $state['identifier']] = 0;
        }
    }
    
    /**
     * @throws Exception
     */
    public function install()
    {
        $this->identifier = $this->step['identifier'];
        $stateDefinition = $this->ioTools->getJsonContents("states/{$this->identifier}.yml");
        $this->stateDefinition = $stateDefinition;

        $groupIdentifier = $this->stateDefinition['group_identifier'];
        $groupNames = $this->stateDefinition['group_name'];
        $states = $this->stateDefinition['states'];

        $this->logger->info("Install state group " . $this->stateDefinition['group_identifier']);

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
                    }
                }
                $messages = array();
                $isValid = $stateObject->isValid($messages);
                if (!$isValid) {
                    throw new Exception(implode(',', $messages));
                }
                $stateObject->store();
            }
            $this->installerVars['state_' . $stateGroup->attribute('identifier') . '_' . $stateObject->attribute('identifier')] = $stateObject->attribute('id');
        }

        OCOpenDataStateRepositoryCache::clearCache();
    }
}