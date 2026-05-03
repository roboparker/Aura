<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds task.reminders (JSON array of offset strings) and creates the
 * notification table for in-app delivery of reminders. The (recipient,
 * task, reminder_offset) unique index is the dispatcher's idempotency
 * backstop — see DispatchTaskRemindersCommand.
 */
final class Version20260503130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add task.reminders and notification table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task ADD reminders JSON DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE notification (
                id UUID NOT NULL,
                recipient_id UUID NOT NULL,
                task_id UUID DEFAULT NULL,
                type VARCHAR(50) NOT NULL,
                title VARCHAR(255) NOT NULL,
                body TEXT DEFAULT NULL,
                reminder_offset VARCHAR(16) DEFAULT NULL,
                read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_notification_recipient_read ON notification (recipient_id, read_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_task_reminder ON notification (recipient_id, task_id, reminder_offset)');
        $this->addSql('CREATE INDEX IDX_BF5476CAE92F8F78 ON notification (recipient_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CA8DB60186 ON notification (task_id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAE92F8F78 FOREIGN KEY (recipient_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA8DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification');
        $this->addSql('ALTER TABLE task DROP reminders');
    }
}
