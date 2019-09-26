<?php

namespace Opencontent\Installer;

use OCClassTools;
use OCOpenDataClassRepositoryCache;
use Opencontent\Installer\Serializer\ContentClassSerializer;

class ContentClass extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $identifier;

    public function dryRun()
    {
        $identifier = $this->step['identifier'];
        $this->logger->info("Install class $identifier");
        $this->installerVars['class_' . $identifier] = 0;
    }

    public function install()
    {
        $this->identifier = $this->step['identifier'];
        $sourcePath = "classes/{$this->identifier}.yml";
        $definitionData = $this->ioTools->getJsonContents($sourcePath);
        $definitionJsonFile = $this->createJsonFile($sourcePath);

        $this->logger->info("Install class $this->identifier");
        $force = isset($this->step['force']) && $this->step['force'];
        if ($force){
            $this->logger->info( ' - forcing sync');
        }
        $removeExtras = isset($this->step['remove_extra']) && $this->step['remove_extra'];
        if ($removeExtras){
            $this->logger->info( ' - removing extra attributes');
        }

        $tools = new OCClassTools($definitionData['identifier'], true, array(), $definitionJsonFile);
        $tools->sync($force, $removeExtras);

        $class = $tools->getLocale();
        $this->installerVars['class_' . $this->identifier] = $class->attribute('id');

        OCOpenDataClassRepositoryCache::clearCache();

        @unlink($definitionJsonFile);
    }

    private function createJsonFile($source)
    {
        $data = $this->ioTools->getJsonContents($source);
        $serializer = new ContentClassSerializer($this->installerVars);
        $data = $serializer->unserialize($data);

        if ($data) {
            $filePath = $this->ioTools->getFile($source);
            $destinationFilePath = substr($filePath, 0, -4) . '.json';
            \eZFile::create(basename($destinationFilePath), dirname($destinationFilePath), json_encode($data));

            return $destinationFilePath;
        }

        return false;
    }
}