<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-user calendar-feed token (#calendar-sync): the sha256-hashed secret that
 * authorises a user's read-only iCal feed at `GET /calendar/feed/{token}.ics`.
 * One row per user (unique user_id); only the hash is stored.
 */
final class Version20260702090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create calendar_feed_token (per-user iCal feed secret).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE calendar_feed_token ('
            . 'id UUID NOT NULL, '
            . 'user_id UUID NOT NULL, '
            . 'token_hash VARCHAR(64) NOT NULL, '
            . 'created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'last_accessed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, '
            . 'PRIMARY KEY (id))',
        );
        $this->addSql('CREATE UNIQUE INDEX uniq_calendar_feed_token_hash ON calendar_feed_token (token_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_calendar_feed_token_user ON calendar_feed_token (user_id)');
        $this->addSql(
            'ALTER TABLE calendar_feed_token ADD CONSTRAINT FK_calendar_feed_token_user '
            . 'FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_feed_token DROP CONSTRAINT FK_calendar_feed_token_user');
        $this->addSql('DROP TABLE calendar_feed_token');
    }
}
