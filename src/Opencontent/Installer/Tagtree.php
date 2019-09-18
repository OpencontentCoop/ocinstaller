<?php

namespace Opencontent\Installer;

use Opencontent\Opendata\Api\Structs\TagStruct;
use Opencontent\Opendata\Api\TagRepository;
use Exception;

class TagTree extends AbstractStepInstaller implements InterfaceStepInstaller
{
    private $remoteHost;

    private $rootTag;


    public function __construct($step)
    {
        $remoteUrl = $step['source'];
        $parts = explode('/api/opendata/v2/tags_tree/', $remoteUrl);
        $this->remoteHost = array_shift($parts);
        $this->rootTag = array_pop($parts);
    }

    /**
     * @throws Exception
     */
    public function install()
    {
        $this->logger->log("Import tag tree " . $this->rootTag);

        $client = new TagClient(
            $this->remoteHost,
            null,
            null,
            'tags_tree'
        );
        $remoteRoot = $client->readTag($this->rootTag);
        $this->recursiveCreateTag($remoteRoot, 0);
    }

    /**
     * @param $name
     * @param $parentTagId
     * @param string $locale
     *
     * @return \Opencontent\Opendata\Api\Values\Tag
     * @throws Exception
     */
    function createTag($recursionLevel, $name, $parentTagId, $locale = 'ita-IT')
    {
        $tagRepository = new TagRepository();

        $struct = new TagStruct();
        $struct->parentTagId = $parentTagId;
        $struct->keyword = $name;
        $struct->locale = $locale;
        $struct->alwaysAvailable = true;

        $result = $tagRepository->create($struct);
        if ($result['message'] == 'success') {
            $this->logger->warning(str_pad('', $recursionLevel, '  ', STR_PAD_LEFT) . ' |- ' . $name);
        } elseif ($result['message'] == 'already exists') {
            $this->logger->log(str_pad('', $recursionLevel, '  ', STR_PAD_LEFT) . ' |- ' . $name);
        }
        $tag = $result['tag'];

        return $tag;
    }

    /**
     * @param $remoteTag
     * @param int $localeParentTagLocation
     * @param int $recursionLevel
     * @throws Exception
     */
    function recursiveCreateTag($remoteTag, $localeParentTagLocation = 0, $recursionLevel = 0)
    {
        $tag = $this->createTag($recursionLevel, $remoteTag['keyword'], $localeParentTagLocation);
        if ($remoteTag['hasChildren']) {
            foreach ($remoteTag['children'] as $remoteTagChild) {
                ++$recursionLevel;
                $this->recursiveCreateTag($remoteTagChild, $tag->id, $recursionLevel);
                --$recursionLevel;
            }
        }
    }
}

