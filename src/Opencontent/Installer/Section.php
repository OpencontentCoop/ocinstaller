<?php

namespace Opencontent\Installer;

use eZSection;
use Exception;

class Section extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $sectionDefinition;

    public function install()
    {
        $identifier = $this->step['identifier'];
        $sectionDefinition = $this->ioTools->getJsonContents("sections/{$identifier}.yml");
        $this->sectionDefinition = $sectionDefinition;

        $name = $this->sectionDefinition['name'];
        $identifier = $this->sectionDefinition['identifier'];
        $navigationPart = $this->sectionDefinition['navigation_part'];

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
}