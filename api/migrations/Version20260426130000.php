<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the invite system: a `user_invite` table holding one row per
 * invited email (unique), plus a `group_invite` join table connecting
 * each invite to the groups its target will join on signup. Tokens are
 * stored as sha256 hashes; the plain token is delivered via email.
 */
final class Version20260426130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_invite and group_invite tables for the group-invite flow.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_invite (id UUID NOT NULL, email VARCHAR(180) NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_invite_email ON user_invite (email)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_invite_token_hash ON user_invite (token_hash)');

        $this->addSql('CREATE TABLE group_invite (id UUID NOT NULL, user_invite_id UUID NOT NULL, user_group_id UUID NOT NULL, invited_by_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_group_invite_invite_group ON group_invite (user_invite_id, user_group_id)');
        $this->addSql('CREATE INDEX idx_group_invite_invite ON group_invite (user_invite_id)');
        $this->addSql('CREATE INDEX idx_group_invite_group ON group_invite (user_group_id)');
        $this->addSql('ALTER TABLE group_invite ADD CONSTRAINT fk_group_invite_invite FOREIGN KEY (user_invite_id) REFERENCES user_invite (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_invite ADD CONSTRAINT fk_group_invite_group FOREIGN KEY (user_group_id) REFERENCES user_group (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE group_invite ADD CONSTRAINT fk_group_invite_invited_by FOREIGN KEY (invited_by_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE group_invite DROP CONSTRAINT fk_group_invite_invited_by');
        $this->addSql('ALTER TABLE group_invite DROP CONSTRAINT fk_group_invite_group');
        $this->addSql('ALTER TABLE group_invite DROP CONSTRAINT fk_group_invite_invite');
        $this->addSql('DROP TABLE group_invite');
        $this->addSql('DROP TABLE user_invite');
    }
}
