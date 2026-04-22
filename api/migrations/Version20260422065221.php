<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260422065221 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add position column to todo for drag-and-drop ordering.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE todo ADD position INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX idx_todo_owner_position ON todo (owner_id, position)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_todo_owner_position');
        $this->addSql('ALTER TABLE todo DROP position');
    }
}
