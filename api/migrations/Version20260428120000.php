<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the email_change_request table backing the two-step
 * email-change flow: confirm via a token sent to the new address,
 * then optionally revert via a separate token sent to the old one.
 */
final class Version20260428120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email_change_request table for the email-change flow.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_change_request (
            id UUID NOT NULL,
            user_id UUID NOT NULL,
            old_email VARCHAR(180) NOT NULL,
            new_email VARCHAR(180) NOT NULL,
            confirm_token_hash VARCHAR(64) NOT NULL,
            revert_token_hash VARCHAR(64) DEFAULT NULL,
            expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            revert_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            reverted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE INDEX idx_email_change_user ON email_change_request (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_email_change_confirm_hash ON email_change_request (confirm_token_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_email_change_revert_hash ON email_change_request (revert_token_hash)');
        $this->addSql('CREATE INDEX idx_email_change_confirm_hash ON email_change_request (confirm_token_hash)');
        $this->addSql('CREATE INDEX idx_email_change_revert_hash ON email_change_request (revert_token_hash)');

        $this->addSql('ALTER TABLE email_change_request ADD CONSTRAINT fk_email_change_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_change_request DROP CONSTRAINT fk_email_change_user');
        $this->addSql('DROP TABLE email_change_request');
    }
}
