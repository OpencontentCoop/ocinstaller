<?php

namespace Opencontent\Installer;

use eZDB;
use Opencontent\Installer\Dumper\Tool;
use Opencontent\Opendata\Api\Structs\TagStruct;
use Opencontent\Opendata\Api\Structs\TagSynonymStruct;
use Opencontent\Opendata\Api\Structs\TagTranslationStruct;
use Opencontent\Opendata\Api\TagRepository;
use Exception;
use Opencontent\Opendata\Api\Values\Tag;
use Psr\Log\NullLogger;

class TagTreeCsv extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $tagRepository;

    private $languages;

    public function __construct()
    {
        $this->tagRepository = new TagRepository();
        $this->languages = ['ita-IT' => 'it', 'eng-GB' => 'en', 'ita-PA' => 'pa', 'ger-DE' => 'de']; //@todo
    }

    public function dryRun(): void
    {
        $updater = new TagTreeCsv\Updater($this->languages, $this->getFiles());
        if ($this->logger->isVerbose) {
            $updater->setLogger($this->logger->setPrefix('  - '));
        } else {
            $updater->setLogger(new NullLogger());
        }
        $updater->setDryRun(true);
        $updater->setRemoveTranslation(false);
        try {
            $updater->run();
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage());
        }
        $this->logger->resetPrefix();
    }

    private function getFiles()
    {
        $identifiers = (array)$this->step['identifiers'];
        $files = [];
        foreach ($identifiers as $identifier) {
            $file = $this->ioTools->getFile('tagtree_csv/' . $identifier . '.csv');
            if (!$file) {
                throw new Exception('File csv not found');
            }
            $this->logger->info("Install tag tree " . $identifier);
            $files[] = $file;
        }

        return $files;
    }

    /**
     * @throws Exception
     */
    public function install(): void
    {
        $identifiers = (array)$this->step['identifiers'];
        $updater = new TagTreeCsv\Updater($this->languages, $this->getFiles());
        if ($this->logger->isVerbose) {
            $updater->setLogger($this->logger->setPrefix('  - '));
        } else {
            $updater->setLogger(new NullLogger());
        }
        $updater->setDryRun(false);
        $updater->setRemoveTranslation(false);
        try {
            $updater->run();
        } catch (Throwable $e) {
            $this->logger->error($e->getMessage());
            $this->logger->resetPrefix();
        }
        $this->logger->resetPrefix();
    }

    public function sync(): void
    {
        throw new Exception('Not yet implemented');
    }
}