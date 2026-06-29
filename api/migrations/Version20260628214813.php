<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-space custom roles (#space-roles), Phase 1.
 *
 * Adds `space_role` (a named per-space CRUD permission matrix) and the
 * `space_membership_role` join (roles assigned to a member). No backfill — a
 * member with no roles is unrestricted, so existing access is unchanged.
 */
final class Version20260628214813 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add space_role + space_membership_role for per-space custom roles (Phase 1, no enforcement).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE space_membership_role (space_membership_id UUID NOT NULL, space_role_id UUID NOT NULL, PRIMARY KEY (space_membership_id, space_role_id))');
        $this->addSql('CREATE INDEX IDX_564BC1593CD349CC ON space_membership_role (space_membership_id)');
        $this->addSql('CREATE INDEX IDX_564BC1595617F9E4 ON space_membership_role (space_role_id)');
        $this->addSql('CREATE TABLE space_role (id UUID NOT NULL, name VARCHAR(80) NOT NULL, color VARCHAR(7) DEFAULT NULL, permissions JSON DEFAULT \'{}\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, space_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_space_role_space ON space_role (space_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_space_role_name ON space_role (space_id, name)');
        $this->addSql('ALTER TABLE space_membership_role ADD CONSTRAINT FK_564BC1593CD349CC FOREIGN KEY (space_membership_id) REFERENCES space_membership (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE space_membership_role ADD CONSTRAINT FK_564BC1595617F9E4 FOREIGN KEY (space_role_id) REFERENCES space_role (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE space_role ADD CONSTRAINT FK_3C1FF0E023575340 FOREIGN KEY (space_id) REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE space_membership_role DROP CONSTRAINT FK_564BC1593CD349CC');
        $this->addSql('ALTER TABLE space_membership_role DROP CONSTRAINT FK_564BC1595617F9E4');
        $this->addSql('ALTER TABLE space_role DROP CONSTRAINT FK_3C1FF0E023575340');
        $this->addSql('DROP TABLE space_membership_role');
        $this->addSql('DROP TABLE space_role');
    }
}
