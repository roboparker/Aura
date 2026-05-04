<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the push_subscription table backing #100. Multiple rows per user
 * are expected (one per device + browser combination); the endpoint URL
 * is unique across the table so re-subscribes from the same browser
 * surface as duplicates rather than silently growing the row set.
 */
final class Version20260504050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add push_subscription table for Web Push delivery (#100).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE push_subscription (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                endpoint TEXT NOT NULL,
                p256dh VARCHAR(255) NOT NULL,
                auth VARCHAR(255) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_push_subscription_user ON push_subscription (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_push_endpoint ON push_subscription (endpoint)');
        $this->addSql('ALTER TABLE push_subscription ADD CONSTRAINT FK_PUSH_USER FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE push_subscription DROP CONSTRAINT FK_PUSH_USER');
        $this->addSql('DROP TABLE push_subscription');
    }
}
