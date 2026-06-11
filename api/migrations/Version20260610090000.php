<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Space data exports: one row per requested export of a space's content.
 *
 * `POST /spaces/{id}/export` inserts a pending row; the async worker builds
 * the zip, stamps the (hashed) download token + expiry, and emails the
 * requester. A nightly prune deletes rows (and their files) past the
 * retention window. See App\Entity\SpaceExport.
 */
final class Version20260610090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add space_export table for requested space data exports.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE space_export (
                id UUID NOT NULL,
                space_id UUID NOT NULL,
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
        $this->addSql('CREATE UNIQUE INDEX uniq_space_export_token_hash ON space_export (token_hash)');
        $this->addSql('CREATE INDEX idx_space_export_space ON space_export (space_id)');
        $this->addSql('CREATE INDEX idx_space_export_requested_by ON space_export (requested_by_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE space_export
                ADD CONSTRAINT fk_space_export_space FOREIGN KEY (space_id)
                REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE space_export
                ADD CONSTRAINT fk_space_export_requested_by FOREIGN KEY (requested_by_id)
                REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE space_export DROP CONSTRAINT fk_space_export_requested_by');
        $this->addSql('ALTER TABLE space_export DROP CONSTRAINT fk_space_export_space');
        $this->addSql('DROP TABLE space_export');
    }
}
