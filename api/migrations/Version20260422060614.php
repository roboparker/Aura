<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260422060614 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create task table with owner foreign key to user.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE task_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE task (id INT NOT NULL, title VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, created_on TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, completed_on TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, owner_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_task_owner ON task (owner_id)');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB257E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP SEQUENCE task_id_seq CASCADE');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB257E3C61F9');
        $this->addSql('DROP TABLE task');
    }
}
