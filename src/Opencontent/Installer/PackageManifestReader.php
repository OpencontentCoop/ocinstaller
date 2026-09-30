<?php

namespace Opencontent\Installer;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads the 'name' and 'version' declared in another package's installer.yml.
 *
 * Used to resolve a `requires` entry: to check a dependency against 'latest'
 * we need to know what version that dependency currently declares in its own
 * manifest (not what's applied on the tenant — that comes from eZSiteData,
 * read separately by the caller).
 */
class PackageManifestReader
{
    /**
     * @param string $packageDir directory containing installer.yml
     * @return array{name: string, version: string}
     * @throws \RuntimeException if the manifest is missing or incomplete
     */
    public static function read(string $packageDir): array
    {
        $path = rtrim($packageDir, '/') . '/installer.yml';

        if (!is_file($path)) {
            throw new \RuntimeException("Manifest not found: $path");
        }

        $data = Yaml::parse(file_get_contents($path));

        if (!isset($data['name']) || !isset($data['version'])) {
            throw new \RuntimeException("Manifest missing 'name' or 'version': $path");
        }

        return [
            'name' => $data['name'],
            'version' => $data['version'],
        ];
    }
}
