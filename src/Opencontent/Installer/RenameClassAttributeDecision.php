<?php

namespace Opencontent\Installer;

/**
 * Decides what a `rename_class_attribute` step should do, given whether the
 * old and new attribute identifiers currently exist on the target class.
 *
 * See docs/superpowers/specs/2026-06-30-rename-class-attribute-design.md,
 * corner cases CC7/CC8. Pure logic: no eZ Publish dependency, no I/O.
 */
class RenameClassAttributeDecision
{
    const RENAME = 'rename';

    const SKIP = 'skip';

    /**
     * @param bool $oldExists whether the 'from' identifier currently exists on the class
     * @param bool $newExists whether the 'to' identifier currently exists on the class
     * @return string self::RENAME or self::SKIP
     * @throws \RuntimeException if the state is a conflict (both exist) or unexpected (neither exists)
     */
    public static function decide(bool $oldExists, bool $newExists): string
    {
        if ($oldExists && !$newExists) {
            return self::RENAME;
        }

        if (!$oldExists && $newExists) {
            return self::SKIP;
        }

        if ($oldExists && $newExists) {
            throw new \RuntimeException(
                'Both the old and new attribute identifiers already exist on this class — cannot determine which is correct. Resolve manually.'
            );
        }

        throw new \RuntimeException(
            'Neither the old nor the new attribute identifier exists on this class — unexpected state.'
        );
    }
}
