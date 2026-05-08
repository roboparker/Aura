<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the `space_invite` join table (#186) so a single `UserInvite`
 * can attach to one or more spaces. Mirrors the existing `group_invite`
 * shape — same unique (user_invite, target) constraint, same indexes,
 * same cascade behavior.
 */
final class Version20260508000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add space_invite join table for per-space UserInvite attachments (#186).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE space_invite (
                id UUID NOT NULL,
                user_invite_id UUID NOT NULL,
                space_id UUID NOT NULL,
                invited_by_id UUID NOT NULL,
                role VARCHAR(16) DEFAULT 'member' NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_space_invite_invite_space ON space_invite (user_invite_id, space_id)');
        $this->addSql('CREATE INDEX idx_space_invite_invite ON space_invite (user_invite_id)');
        $this->addSql('CREATE INDEX idx_space_invite_space ON space_invite (space_id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE space_invite
            ADD CONSTRAINT fk_space_invite_user_invite
            FOREIGN KEY (user_invite_id) REFERENCES user_invite (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE space_invite
            ADD CONSTRAINT fk_space_invite_space
            FOREIGN KEY (space_id) REFERENCES space (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE space_invite
            ADD CONSTRAINT fk_space_invite_invited_by
            FOREIGN KEY (invited_by_id) REFERENCES "user" (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE space_invite');
    }
}
