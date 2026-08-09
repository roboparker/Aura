<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Extend the deletion grace period from organizations to **spaces and
 * accounts**, and add the restore tokens behind the emailed "undo" link.
 *
 * All three cascade when deleted — a space takes its boards, tasks and
 * comments; an account reassigns its authorship to a sentinel — so all three
 * now stamp a window instead of destroying on confirm.
 *
 * `restore_token` is polymorphic by (target_type, target_id) rather than
 * carrying three nullable FKs: an account's token has to keep working while the
 * account itself can't sign in, and the row is meaningless once the purge runs,
 * so a cascade would buy nothing. The purge prunes spent rows explicitly.
 *
 * No backfill — every existing space and account is live, which is exactly what
 * two NULLs mean.
 */
final class Version20260808150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add space/user deletion grace columns and the restore_token table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE space ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE space ADD purge_after TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_space_purge_after ON space (purge_after)');

        $this->addSql('ALTER TABLE "user" ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD purge_after TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_user_purge_after ON "user" (purge_after)');

        $this->addSql(<<<'SQL'
            CREATE TABLE restore_token (
                id UUID NOT NULL,
                target_type VARCHAR(32) NOT NULL,
                target_id UUID NOT NULL,
                target_label VARCHAR(255) NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX UNIQ_restore_token_hash ON restore_token (token_hash)');
        $this->addSql('CREATE INDEX idx_restore_token_hash ON restore_token (token_hash)');
        $this->addSql('CREATE INDEX idx_restore_token_target ON restore_token (target_type, target_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE restore_token');
        $this->addSql('DROP INDEX idx_user_purge_after');
        $this->addSql('ALTER TABLE "user" DROP purge_after');
        $this->addSql('ALTER TABLE "user" DROP deleted_at');
        $this->addSql('DROP INDEX idx_space_purge_after');
        $this->addSql('ALTER TABLE space DROP purge_after');
        $this->addSql('ALTER TABLE space DROP deleted_at');
    }
}
