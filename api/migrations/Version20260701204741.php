<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-project opt-in for global custom fields.
 *
 * Adds `project_global_custom_field_definition` — the join table by which a
 * project opts into instance-wide global fields (the global twin of
 * `project_custom_field_definition`). Also makes `project_field_visibility`
 * polymorphic: a nullable `global_definition_id` alongside the now-nullable
 * `definition_id`, with a CHECK enforcing exactly one, so a project can
 * override where a global field shows just like a space field.
 */
final class Version20260701204741 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add per-project opt-in + visibility overrides for global custom fields.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE project_global_custom_field_definition (project_id UUID NOT NULL, global_custom_field_definition_id UUID NOT NULL, PRIMARY KEY (project_id, global_custom_field_definition_id))');
        $this->addSql('CREATE INDEX IDX_9A4C797C166D1F9C ON project_global_custom_field_definition (project_id)');
        $this->addSql('CREATE INDEX IDX_9A4C797C117A7288 ON project_global_custom_field_definition (global_custom_field_definition_id)');
        $this->addSql('ALTER TABLE project_global_custom_field_definition ADD CONSTRAINT FK_9A4C797C166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE project_global_custom_field_definition ADD CONSTRAINT FK_9A4C797C117A7288 FOREIGN KEY (global_custom_field_definition_id) REFERENCES global_custom_field_definition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE project_field_visibility ADD global_definition_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE project_field_visibility ALTER definition_id DROP NOT NULL');
        $this->addSql('ALTER TABLE project_field_visibility ADD CONSTRAINT FK_E183E9D1C3AA8C40 FOREIGN KEY (global_definition_id) REFERENCES global_custom_field_definition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_pfv_global_definition ON project_field_visibility (global_definition_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_pfv_project_global_definition ON project_field_visibility (project_id, global_definition_id)');
        // Exactly one field source per override row.
        $this->addSql(<<<'SQL'
            ALTER TABLE project_field_visibility
            ADD CONSTRAINT chk_pfv_definition_exactly_one
            CHECK (
                (definition_id IS NOT NULL AND global_definition_id IS NULL)
                OR (definition_id IS NULL AND global_definition_id IS NOT NULL)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project_field_visibility DROP CONSTRAINT chk_pfv_definition_exactly_one');
        $this->addSql('ALTER TABLE project_global_custom_field_definition DROP CONSTRAINT FK_9A4C797C166D1F9C');
        $this->addSql('ALTER TABLE project_global_custom_field_definition DROP CONSTRAINT FK_9A4C797C117A7288');
        $this->addSql('DROP TABLE project_global_custom_field_definition');
        $this->addSql('ALTER TABLE project_field_visibility DROP CONSTRAINT FK_E183E9D1C3AA8C40');
        $this->addSql('DROP INDEX idx_pfv_global_definition');
        $this->addSql('DROP INDEX uniq_pfv_project_global_definition');
        $this->addSql('ALTER TABLE project_field_visibility DROP global_definition_id');
        $this->addSql('ALTER TABLE project_field_visibility ALTER definition_id SET NOT NULL');
    }
}
