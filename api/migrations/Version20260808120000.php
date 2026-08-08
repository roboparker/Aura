<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Organization soft deletion (#billing Phase 1c).
 *
 * Adds the two columns that turn deleting an org from an event into a state:
 * `deleted_at` (when an owner asked for it — non-null means the grace period is
 * running and the access extensions hide the org's spaces) and `purge_after`
 * (when the nightly job may hard-delete it).
 *
 * `purge_after` is stored rather than derived from `deleted_at + N days` so
 * shortening the configured grace window can never retroactively bring forward
 * a deletion someone was already promised — the date they were shown is the
 * date that binds.
 *
 * Both nullable with no backfill: every existing organization is live, which is
 * exactly what two NULLs mean. The index on `purge_after` covers the nightly
 * "what's due?" query — declared on the entity too, so schema:validate stays
 * clean.
 */
final class Version20260808120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add organization.deleted_at + purge_after for the deletion grace period.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE organization ADD purge_after TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_organization_purge_after ON organization (purge_after)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_organization_purge_after');
        $this->addSql('ALTER TABLE organization DROP purge_after');
        $this->addSql('ALTER TABLE organization DROP deleted_at');
    }
}
