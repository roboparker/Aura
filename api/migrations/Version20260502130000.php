<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the task_assignee join table linking tasks to the users responsible
 * for them. The composite PK (task_id, user_id) prevents duplicate
 * assignments at the database layer; the ValidAssignees constraint
 * keeps the set restricted to owner ∪ project members at the app layer.
 */
final class Version20260502130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add task_assignee join table for assigning users to tasks.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE task_assignee (
            task_id UUID NOT NULL,
            user_id UUID NOT NULL,
            PRIMARY KEY (task_id, user_id)
        )');
        $this->addSql('CREATE INDEX IDX_TA_TASK ON task_assignee (task_id)');
        $this->addSql('CREATE INDEX IDX_TA_USER ON task_assignee (user_id)');
        $this->addSql('ALTER TABLE task_assignee ADD CONSTRAINT FK_TA_TASK FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_assignee ADD CONSTRAINT FK_TA_USER FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_assignee DROP CONSTRAINT FK_TA_USER');
        $this->addSql('ALTER TABLE task_assignee DROP CONSTRAINT FK_TA_TASK');
        $this->addSql('DROP TABLE task_assignee');
    }
}
