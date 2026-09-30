<?php

namespace Opencontent\Installer;

/**
 * Resolves a package reference used in a `requires` entry (a module folder
 * name under modules/, or the literal 'main') to the filesystem directory
 * containing that package's installer.yml.
 *
 * Pure path logic: no filesystem access, no eZ Publish dependency.
 */
class PackageResolver
{
    /** @var string */
    private $rootDir;

    /**
     * @param string $rootDir absolute path to the main installer's directory
     *                        (the one containing the top-level installer.yml)
     */
    public function __construct(string $rootDir)
    {
        $this->rootDir = rtrim($rootDir, '/');
    }

    /**
     * @param string $package 'main', or a module folder name under modules/ (e.g. 'trasparenza-c1')
     * @return string absolute path to that package's directory
     */
    public function resolvePackageDir(string $package): string
    {
        if ($package === 'main') {
            return $this->rootDir;
        }

        return $this->rootDir . '/modules/' . $package;
    }
}
