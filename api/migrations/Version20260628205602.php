<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-project custom-field visibility (#custom-fields-project).
 *
 * Adds `project_field_visibility` — one row per (project, definition) pair that
 * overrides where a field is shown (list / board / both) within that project.
 * No backfill: absent a row, the definition's own `visibility` column remains
 * the default, so existing projects keep their current behaviour.
 */
final class Version20260628205602 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project_field_visibility for per-project custom-field visibility overrides.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE project_field_visibility (id UUID NOT NULL, visibility VARCHAR(16) DEFAULT \'both\' NOT NULL, project_id UUID NOT NULL, definition_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E183E9D1166D1F9C ON project_field_visibility (project_id)');
        $this->addSql('CREATE INDEX idx_pfv_definition ON project_field_visibility (definition_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_pfv_project_definition ON project_field_visibility (project_id, definition_id)');
        $this->addSql('ALTER TABLE project_field_visibility ADD CONSTRAINT FK_E183E9D1166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE project_field_visibility ADD CONSTRAINT FK_E183E9D1D11EA911 FOREIGN KEY (definition_id) REFERENCES custom_field_definition (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE project_field_visibility DROP CONSTRAINT FK_E183E9D1166D1F9C');
        $this->addSql('ALTER TABLE project_field_visibility DROP CONSTRAINT FK_E183E9D1D11EA911');
        $this->addSql('DROP TABLE project_field_visibility');
    }
}
