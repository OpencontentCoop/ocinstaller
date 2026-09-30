<?php

namespace Opencontent\Installer;

/**
 * Evaluates a package's version-based dependency constraint against another
 * package's currently-applied version on a tenant.
 *
 * Pure logic: no eZ Publish dependency, no I/O. Callers are responsible for
 * resolving $currentVersion (the dependency's version applied on this tenant)
 * and, for a "latest" constraint, $declaredVersion (the version the dependency
 * itself currently declares in its own manifest).
 */
class RequirementChecker
{
    /**
     * @param string $currentVersion  version of the dependency currently applied on the tenant (e.g. '0.0.0' if never installed)
     * @param string $declaredVersion version the dependency currently declares in its own manifest (used only for the 'latest' constraint)
     * @param string $constraint      either 'latest', or a comparison string like '>=3.2.0', '>3.2.0', '<=3.2.0', '<3.2.0', '==3.2.0'
     * @return bool
     * @throws \InvalidArgumentException if $constraint is not a recognized format
     */
    public static function isSatisfied(string $currentVersion, string $declaredVersion, string $constraint): bool
    {
        if ($constraint === 'latest') {
            return version_compare($currentVersion, $declaredVersion, '==');
        }

        if (preg_match('/^(>=|<=|==|>|<)?\s*([\d.]+)$/', $constraint, $matches)) {
            $operator = $matches[1] !== '' ? $matches[1] : '>=';
            return version_compare($currentVersion, $matches[2], $operator);
        }

        throw new \InvalidArgumentException("Invalid version constraint: $constraint");
    }
}
