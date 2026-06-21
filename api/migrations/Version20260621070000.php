<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add custom_field_definition.visibility — controls whether a field is
 * shown in the project task list, the Kanban board cards, or both
 * (default). Existing fields keep showing everywhere.
 */
final class Version20260621070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add custom_field_definition.visibility (list|board|both, default both).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE custom_field_definition ADD COLUMN visibility VARCHAR(16) NOT NULL DEFAULT 'both'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE custom_field_definition DROP COLUMN visibility');
    }
}
