<?php

namespace Opencontent\Installer;

/**
 * Evaluates the `requires` and `conflicts` entries of a package manifest
 * against the versions applied on the tenant, WITHOUT throwing on violations:
 * it returns them as a list, so that both the installer (which stops, or
 * ignores them on request) and a GUI (which disables the install/update
 * button and shows the reason) can share the same logic.
 *
 * No eZ Publish dependency: the version applied on the tenant is provided
 * by the caller through $appliedVersionResolver.
 */
class ConstraintEvaluator
{
    const KIND_REQUIRES = 'requires';

    const KIND_CONFLICTS = 'conflicts';

    /**
     * @param array $installerData parsed installer.yml of the package under evaluation
     * @param string $rootDir absolute path of the main installer directory
     * @param callable $appliedVersionResolver function(string $package, string $packageName): string
     *                                         returns the version applied on the tenant ('0.0.0' if never installed)
     * @return array<int, array{kind: string, package: string, package_name: string, constraint: ?string, current: ?string, message: string}>
     *         empty when every constraint is satisfied; `requires` violations come first
     */
    public static function evaluate(array $installerData, string $rootDir, callable $appliedVersionResolver): array
    {
        $violations = [];
        $resolver = new PackageResolver($rootDir);
        $name = $installerData['name'] ?? '?';

        foreach ($installerData['requires'] ?? [] as $requirement) {
            $package = $requirement['package'];
            $constraint = $requirement['version'];

            try {
                $manifest = PackageManifestReader::read($resolver->resolvePackageDir($package));
                $current = (string)$appliedVersionResolver($package, $manifest['name']);
                $satisfied = RequirementChecker::isSatisfied($current, $manifest['version'], $constraint);
            } catch (\Throwable $e) {
                $violations[] = [
                    'kind' => self::KIND_REQUIRES,
                    'package' => $package,
                    'package_name' => $package,
                    'constraint' => $constraint,
                    'current' => null,
                    'message' => sprintf(
                        "'%s' requires package '%s' at version %s, which cannot be evaluated: %s",
                        $name,
                        $package,
                        $constraint,
                        $e->getMessage()
                    ),
                ];
                continue;
            }

            if (!$satisfied) {
                $violations[] = [
                    'kind' => self::KIND_REQUIRES,
                    'package' => $package,
                    'package_name' => $manifest['name'],
                    'constraint' => $constraint,
                    'current' => $current,
                    'message' => sprintf(
                        "'%s' requires package '%s' at version %s, but the tenant currently has it at %s.",
                        $name,
                        $manifest['name'],
                        $constraint,
                        $current
                    ),
                ];
            }
        }

        foreach ($installerData['conflicts'] ?? [] as $package) {
            try {
                $manifest = PackageManifestReader::read($resolver->resolvePackageDir($package));
                $current = (string)$appliedVersionResolver($package, $manifest['name']);
            } catch (\Throwable $e) {
                // same strictness as `requires`: a manifest that cannot be read (e.g. a typo) stops the installer
                $violations[] = [
                    'kind' => self::KIND_CONFLICTS,
                    'package' => $package,
                    'package_name' => $package,
                    'constraint' => null,
                    'current' => null,
                    'message' => sprintf(
                        "'%s' declares a conflict with package '%s', which cannot be evaluated: %s",
                        $name,
                        $package,
                        $e->getMessage()
                    ),
                ];
                continue;
            }

            if (ConflictChecker::isInstalled($current)) {
                $violations[] = [
                    'kind' => self::KIND_CONFLICTS,
                    'package' => $package,
                    'package_name' => $manifest['name'],
                    'constraint' => null,
                    'current' => $current,
                    'message' => sprintf(
                        "'%s' conflicts with package '%s', which is already installed on this tenant (version %s).",
                        $name,
                        $manifest['name'],
                        $current
                    ),
                ];
            }
        }

        return $violations;
    }
}
