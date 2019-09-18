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