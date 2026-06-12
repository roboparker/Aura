<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Account data exports: one row per requested GDPR export of a single user's
 * own data.
 *
 * `POST /me/export` inserts a pending row; the async worker builds the zip,
 * stamps the (hashed) download token + expiry, and emails the requester. A
 * nightly prune deletes rows (and their files) past the retention window.
 * See App\Entity\AccountExport.
 */
final class Version20260611120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account_export table for requested GDPR account data exports.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE account_export (
                id UUID NOT NULL,
                requested_by_id UUID NOT NULL,
                status VARCHAR(16) NOT NULL,
                token_hash VARCHAR(64) DEFAULT NULL,
                file_path VARCHAR(255) DEFAULT NULL,
                file_size BIGINT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_account_export_token_hash ON account_export (token_hash)');
        $this->addSql('CREATE INDEX idx_account_export_requested_by ON account_export (requested_by_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE account_export
                ADD CONSTRAINT fk_account_export_requested_by FOREIGN KEY (requested_by_id)
                REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE account_export DROP CONSTRAINT fk_account_export_requested_by');
        $this->addSql('DROP TABLE account_export');
    }
}
