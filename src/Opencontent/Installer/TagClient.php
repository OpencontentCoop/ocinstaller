<?php

namespace Opencontent\Installer;

use Opencontent\Opendata\Rest\Client\HttpClient;

class TagClient extends HttpClient
{
    /**
     * @param $name
     * @return mixed
     * @throws \Exception
     */
    public function readTag($name, $offset = 0, $limit = 100)
    {
        return $this->request('GET', $this->buildUrl(urlencode($name) . "?limit=$limit&offset=$offset"));
    }

    protected function buildUrl($path)
    {
        $request = $this->server . $this->apiEndPointBase . '/' . $this->apiEnvironmentPreset . '/' . $path;

        return $request;
    }

    public function readTree($name)
    {
        $offset = 0;
        $limit = 100;
        $rootTag = $this->request('GET', $this->buildUrl(urlencode($name) . "?limit=$limit&offset=$offset"));

        if ($rootTag['hasChildren']){
            while ($rootTag['childrenCount'] > count($rootTag['children'])){
                $offset = $offset + $limit;
                $offsetRootTag = $this->request('GET', $this->buildUrl(urlencode($name) . "?limit=$limit&offset=$offset"));
                $rootTag['children'] = array_merge(
                    $rootTag['children'],
                    $offsetRootTag['children']
                );
            }

            foreach ($rootTag['children'] as $index => $child){
                if ($child['hasChildren']) {
                    $rootTag['children'][$index] = $this->readTree($child['id']);
                }
            }
        }

        return $rootTag;
    }
}