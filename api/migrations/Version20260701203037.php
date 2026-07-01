<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Global custom fields (instance-wide, admin-managed).
 *
 * Adds `global_custom_field_definition` — the sibling of
 * `custom_field_definition` that belongs to no space and is available to
 * every project. Same (kind, subtype, config, footer, nullable) shape, so it
 * reuses the whole custom-field type-strategy system. Names are unique across
 * the instance. Per-project opt-in + polymorphic task values land in later
 * migrations.
 */
final class Version20260701203037 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add global_custom_field_definition (instance-wide, admin-managed custom fields).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE global_custom_field_definition (id UUID NOT NULL, name VARCHAR(80) NOT NULL, kind VARCHAR(16) NOT NULL, subtype VARCHAR(24) NOT NULL, config JSON DEFAULT \'{}\' NOT NULL, footer JSON DEFAULT NULL, nullable BOOLEAN DEFAULT true NOT NULL, position INT DEFAULT 0 NOT NULL, visibility VARCHAR(16) DEFAULT \'both\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_gcfd_name ON global_custom_field_definition (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE global_custom_field_definition');
    }
}
