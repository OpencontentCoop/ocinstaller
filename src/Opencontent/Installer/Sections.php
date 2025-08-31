<?php

namespace Opencontent\Installer;

use eZSection;
use Exception;
use Opencontent\Opendata\Api\SectionRepository;

class Sections extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun(): void
    {
        $identifiers = (array)$this->step['identifiers'];
        $this->logger->info("Install sections " . implode(', ', $identifiers));
        foreach ($identifiers as $identifier) {
            $this->installerVars['section_' . $identifier] = 0;
        }
    }

    public function install(): void
    {
        $identifiers = (array)$this->step['identifiers'];
        foreach ($identifiers as $identifier) {
            $sectionDefinition = $this->ioTools->getJsonContents("sections/{$identifier}.yml");
            $name = $sectionDefinition['name'];
            $identifier = $sectionDefinition['identifier'];
            $navigationPart = isset($sectionDefinition['navigation_part']) ? $sectionDefinition['navigation_part'] : 'ezcontentnavigationpart';
            $this->logger->info("Install section " . $identifier);
            $section = eZSection::fetchByIdentifier($identifier, false);
            if (isset($section['id'])) {
                $section = eZSection::fetch($section['id']);
            }
            if (!$section instanceof eZSection) {
                $section = new eZSection(array());
                $section->setAttribute('name', $name);
                $section->setAttribute('identifier', $identifier);
                $section->setAttribute('navigation_part_identifier', $navigationPart);
                $section->store();
            }
            if (!$section instanceof eZSection) {
                throw new Exception("Section $identifier not found");
            }
            $this->installerVars['section_' . $section->attribute('identifier')] = $section->attribute('id');
        }

        $repository = new SectionRepository();
        $repository->clearCache();
    }

}