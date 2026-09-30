<?php

namespace Opencontent\Installer;

use eZExpiryHandler;
use Exception;

/**
 * Renames a content class attribute's identifier directly in the DB, without
 * losing the data of existing objects (they're linked to the attribute's
 * numeric id, not its identifier — see the "Analisi tecnica del DB" section
 * of docs/superpowers/specs/2026-06-30-rename-class-attribute-design.md).
 *
 * YAML usage:
 *   - type: rename_class_attribute
 *     class: public_service
 *     from: ife_event
 *     to: life_events
 *     reindex: true   # optional, default false — reindex the class in Solr
 *                      # after a rename actually happens (never on skip)
 *
 * Updates both the published (version=0) and draft (version=1) rows (CC1),
 * always scoped to the target class's contentclass_id (CC9), and is
 * idempotent across re-runs (CC7/CC8, see RenameClassAttributeDecision):
 * a rename already applied in a previous run is silently skipped, a genuine
 * conflict (both identifiers present, or neither) throws instead of guessing.
 *
 * Out of scope (left to the dev, per CC4/CC5/CC10): hardcoded identifier
 * references in other extensions' code, the Kafka payload field map, and
 * the Kafka schema registry. The class YAML under classes/{class}.yml must
 * be updated separately before the next `class` step run for this class,
 * otherwise it will recreate the old identifier as a duplicate attribute.
 */
class RenameClassAttribute extends AbstractStepInstaller implements InterfaceStepInstaller
{
    public function dryRun(): void
    {
        $class = $this->step['class'];
        $from = $this->step['from'];
        $to = $this->step['to'];

        $this->logger->info("Rename class attribute $class: $from -> $to");

        $classId = $this->getClassId($class);
        $oldExists = $this->attributeExists($classId, $from);
        $newExists = $this->attributeExists($classId, $to);

        try {
            $decision = RenameClassAttributeDecision::decide($oldExists, $newExists);
            if ($decision === RenameClassAttributeDecision::RENAME) {
                $this->logger->info(" -> would rename ($from -> $to)");
                if ($this->wantsReindex()) {
                    $this->logger->info(" -> would reindex $class");
                }
            } else {
                $this->logger->info(" -> would skip (already renamed in a previous run)");
            }
        } catch (\RuntimeException $e) {
            $this->logger->warning(' -> ' . $e->getMessage());
        }
    }

    public function install(): void
    {
        $class = $this->step['class'];
        $from = $this->step['from'];
        $to = $this->step['to'];

        $this->logger->info("Rename class attribute $class: $from -> $to");

        $classId = $this->getClassId($class);
        $oldExists = $this->attributeExists($classId, $from);
        $newExists = $this->attributeExists($classId, $to);

        $decision = RenameClassAttributeDecision::decide($oldExists, $newExists);

        if ($decision === RenameClassAttributeDecision::SKIP) {
            $this->logger->info(' -> skip (already renamed in a previous run)');
            return;
        }

        $fromEscaped = $this->db->escapeString($from);
        $toEscaped = $this->db->escapeString($to);

        // No version filter: renames both the published (version=0) and any
        // draft (version=1) row for this attribute (CC1).
        $this->db->query(
            "UPDATE ezcontentclass_attribute
             SET identifier = '$toEscaped'
             WHERE contentclass_id = $classId
               AND identifier = '$fromEscaped'"
        );

        $handler = eZExpiryHandler::instance();
        $handler->setTimestamp('class-identifier-cache', -1);

        $this->logger->info(' -> renamed');

        if ($this->wantsReindex()) {
            $this->reindex($class);
        }
    }

    private function wantsReindex(): bool
    {
        return isset($this->step['reindex']) && $this->step['reindex'];
    }

    private function reindex(string $classIdentifier): void
    {
        $this->logger->info(" -> reindex $classIdentifier");

        $reindex = new Reindex();
        $reindex->setLogger($this->logger);
        $reindex->setInstallerVars($this->installerVars);
        $reindex->setIoTools($this->ioTools);
        $reindex->setDb($this->db);
        $reindex->setStep(['identifier' => $classIdentifier]);
        $reindex->install();
    }

    private function getClassId(string $classIdentifier): int
    {
        $escaped = $this->db->escapeString($classIdentifier);
        $row = $this->db->arrayQuery(
            "SELECT id FROM ezcontentclass WHERE identifier = '$escaped' AND version = 0"
        );

        if (empty($row[0]['id'])) {
            throw new Exception("Class $classIdentifier not found");
        }

        return (int)$row[0]['id'];
    }

    /**
     * Whether $identifier exists for this class, on any version (published
     * or draft) — always scoped to $classId (CC9), never a bare global check.
     */
    private function attributeExists(int $classId, string $identifier): bool
    {
        $escaped = $this->db->escapeString($identifier);
        $row = $this->db->arrayQuery(
            "SELECT COUNT(*) as count FROM ezcontentclass_attribute
             WHERE contentclass_id = $classId
               AND identifier = '$escaped'"
        );

        return !empty($row[0]['count']) && (int)$row[0]['count'] > 0;
    }
}
