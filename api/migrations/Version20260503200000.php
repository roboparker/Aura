<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the task_attachment join table for the Task↔MediaObject many-to-many.
 * Both FKs cascade-delete: removing a task or the underlying media object
 * sweeps the join row along.
 */
final class Version20260503200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add task_attachment join table for task file attachments.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE task_attachment (
                task_id UUID NOT NULL,
                media_object_id UUID NOT NULL,
                PRIMARY KEY(task_id, media_object_id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_654C92148DB60186 ON task_attachment (task_id)');
        $this->addSql('CREATE INDEX IDX_654C921464DE5A5 ON task_attachment (media_object_id)');
        $this->addSql('ALTER TABLE task_attachment ADD CONSTRAINT FK_654C92148DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE task_attachment ADD CONSTRAINT FK_654C921464DE5A5 FOREIGN KEY (media_object_id) REFERENCES media_object (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_attachment DROP CONSTRAINT FK_654C92148DB60186');
        $this->addSql('ALTER TABLE task_attachment DROP CONSTRAINT FK_654C921464DE5A5');
        $this->addSql('DROP TABLE task_attachment');
    }
}
