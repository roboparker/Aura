<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds custom_field_definition for per-project field schemas (#84).
 * Values themselves land in a follow-up — this migration only sets up
 * the definitions so the project-settings UI can be built first.
 */
final class Version20260504060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add custom_field_definition table for per-project field schemas (#84).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE custom_field_definition (
                id UUID NOT NULL,
                project_id UUID NOT NULL,
                name VARCHAR(80) NOT NULL,
                type VARCHAR(16) NOT NULL,
                options JSON DEFAULT NULL,
                position INT NOT NULL DEFAULT 0,
                required BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_cfd_project_position ON custom_field_definition (project_id, position)');
        $this->addSql('CREATE UNIQUE INDEX uniq_cfd_project_name ON custom_field_definition (project_id, name)');
        $this->addSql('ALTER TABLE custom_field_definition ADD CONSTRAINT FK_CFD_PROJECT FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE custom_field_definition DROP CONSTRAINT FK_CFD_PROJECT');
        $this->addSql('DROP TABLE custom_field_definition');
    }
}
