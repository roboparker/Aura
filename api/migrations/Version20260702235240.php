<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702235240 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calendar sync (#582 Phase D): provider change-notification subscriptions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE calendar_subscription (id UUID NOT NULL, provider VARCHAR(32) NOT NULL, channel_id VARCHAR(255) NOT NULL, resource_id VARCHAR(255) DEFAULT NULL, secret VARCHAR(128) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, connection_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_calendar_subscription_channel ON calendar_subscription (channel_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_calendar_subscription_connection ON calendar_subscription (connection_id)');
        $this->addSql('ALTER TABLE calendar_subscription ADD CONSTRAINT FK_42B80075DD03F01 FOREIGN KEY (connection_id) REFERENCES oauth_connection (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE calendar_subscription DROP CONSTRAINT FK_42B80075DD03F01');
        $this->addSql('DROP TABLE calendar_subscription');
    }
}
