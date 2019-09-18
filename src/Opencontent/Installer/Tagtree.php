<?php

use Opencontent\Opendata\Rest\Client\HttpClient;
use Opencontent\Opendata\Api\Structs\TagStruct;
use Opencontent\Opendata\Api\TagRepository;

class OpenContentTagTreeInstaller
{
    private $remoteHost;

    private $rootTag;

    public $verbose = false;

    public function __construct($remoteUrl)
    {
        $parts = explode('/api/opendata/v2/tags_tree/', $remoteUrl);
        $this->remoteHost = array_shift($parts);
        $this->rootTag = array_pop($parts);
    }

    /**
     * @throws Exception
     */
    public function import()
    {
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
            if ($this->verbose) eZCLI::instance()->warning(str_pad('', $recursionLevel, '  ', STR_PAD_LEFT) . ' |- ' . $name);
        } elseif ($result['message'] == 'already exists') {
            if ($this->verbose) eZCLI::instance()->output(str_pad('', $recursionLevel, '  ', STR_PAD_LEFT) . ' |- ' . $name);
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

class TagClient extends HttpClient
{
    public function readTag($name)
    {
        return $this->request('GET', $this->buildUrl($name));
    }

    protected function buildUrl($path)
    {
        $request = $this->server . $this->apiEndPointBase . '/' . $this->apiEnvironmentPreset . '/' . urlencode($path);

        return $request;
    }
}