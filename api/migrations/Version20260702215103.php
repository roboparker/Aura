<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702215103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calendar sync (#582): task ↔ external calendar event mapping.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE calendar_event_link (id UUID NOT NULL, provider VARCHAR(32) NOT NULL, external_event_id VARCHAR(255) NOT NULL, calendar_id VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, task_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_calendar_event_link_task ON calendar_event_link (task_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_calendar_event_link_task_provider ON calendar_event_link (task_id, provider)');
        $this->addSql('ALTER TABLE calendar_event_link ADD CONSTRAINT FK_BF2D115F8DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE calendar_event_link DROP CONSTRAINT FK_BF2D115F8DB60186');
        $this->addSql('DROP TABLE calendar_event_link');
    }
}
