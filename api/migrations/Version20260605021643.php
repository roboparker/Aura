<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notification platform redesign: adds `actor_id` (who triggered it,
 * SET NULL on delete), `archived_at` (inbox archive), and `discussion_id`
 * / `page_id` deep-link parents alongside the existing task / comment
 * FKs, plus the (recipient, archived_at) index for the active-inbox vs
 * archived split.
 */
final class Version20260605021643 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add actor, archivedAt, and discussion/page deep-link FKs to notification.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD archived_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD actor_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD discussion_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD page_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA10DAF24A FOREIGN KEY (actor_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA1ADED311 FOREIGN KEY (discussion_id) REFERENCES discussion (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAC4663E4 FOREIGN KEY (page_id) REFERENCES page (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_BF5476CA10DAF24A ON notification (actor_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CA1ADED311 ON notification (discussion_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CAC4663E4 ON notification (page_id)');
        $this->addSql('CREATE INDEX idx_notification_recipient_archived ON notification (recipient_id, archived_at)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA10DAF24A');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CA1ADED311');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CAC4663E4');
        $this->addSql('DROP INDEX IDX_BF5476CA10DAF24A');
        $this->addSql('DROP INDEX IDX_BF5476CA1ADED311');
        $this->addSql('DROP INDEX IDX_BF5476CAC4663E4');
        $this->addSql('DROP INDEX idx_notification_recipient_archived');
        $this->addSql('ALTER TABLE notification DROP archived_at');
        $this->addSql('ALTER TABLE notification DROP actor_id');
        $this->addSql('ALTER TABLE notification DROP discussion_id');
        $this->addSql('ALTER TABLE notification DROP page_id');
    }
}
