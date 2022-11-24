<?php

namespace Opencontent\Installer;

use eZContentObjectStateGroup;
use eZContentObjectState;
use eZContentObjectStateLanguage;
use eZContentLanguage;
use Exception;

class States extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun()
    {
        $identifiers = (array)$this->step['identifiers'];
        $this->logger->info("Install state groups " . implode(', ', $identifiers));
        foreach ($identifiers as $identifier) {
            $stateDefinition = $this->ioTools->getJsonContents("states/{$identifier}.yml");
            foreach ($stateDefinition['states'] as $state) {
                $this->installerVars['state_' . $stateDefinition['group_identifier'] . '_' . $state['identifier']] = 0;
            }
        }
    }

    public function install()
    {
        $identifiers = (array)$this->step['identifiers'];
        foreach ($identifiers as $identifier) {
            $stateDefinition = $this->ioTools->getJsonContents("states/{$identifier}.yml");
            $groupIdentifier = $stateDefinition['group_identifier'];
            $groupNames = $stateDefinition['group_name'];
            $states = $stateDefinition['states'];

            $this->logger->info("Install state group " . $stateDefinition['group_identifier']);

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
        }
    }

}