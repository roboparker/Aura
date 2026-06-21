<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260621063449 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add task_relationship (directed links between tasks: parent/required/related/duplicate).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE task_relationship (id UUID NOT NULL, type VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, source_id UUID NOT NULL, target_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_task_relationship_source ON task_relationship (source_id)');
        $this->addSql('CREATE INDEX idx_task_relationship_target ON task_relationship (target_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_task_relationship ON task_relationship (source_id, target_id, type)');
        $this->addSql('ALTER TABLE task_relationship ADD CONSTRAINT FK_E895608D953C1C61 FOREIGN KEY (source_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_relationship ADD CONSTRAINT FK_E895608D158E0B66 FOREIGN KEY (target_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE task_relationship DROP CONSTRAINT FK_E895608D953C1C61');
        $this->addSql('ALTER TABLE task_relationship DROP CONSTRAINT FK_E895608D158E0B66');
        $this->addSql('DROP TABLE task_relationship');
    }
}
