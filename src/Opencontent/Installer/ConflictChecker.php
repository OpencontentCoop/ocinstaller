<?php

namespace Opencontent\Installer;

/**
 * Decides whether a package that another package declares in `conflicts`
 * is present on the tenant.
 *
 * Pure logic: no eZ Publish dependency, no I/O. The caller resolves the
 * version applied on the tenant ('0.0.0' when the package was never installed,
 * same convention used for `requires`).
 */
class ConflictChecker
{
    /**
     * @param string $currentVersion version of the package applied on the tenant ('0.0.0' if never installed)
     * @return bool true if the package is installed on the tenant
     */
    public static function isInstalled(string $currentVersion): bool
    {
        return version_compare($currentVersion, '0.0.0', '>');
    }
}
