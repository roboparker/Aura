<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the Groups feature: a `user_group` table (the SQL keyword `group`
 * forces the prefix) and a `user_group_member` join table linking users to
 * groups. Owner has full control; non-owner members are read-only at the
 * API layer (enforced via security expressions on the resource).
 */
final class Version20260426120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_group and user_group_member tables for the Groups feature.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_group (id UUID NOT NULL, owner_id UUID NOT NULL, title VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, created_on TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_user_group_owner ON user_group (owner_id)');
        $this->addSql('ALTER TABLE user_group ADD CONSTRAINT fk_user_group_owner FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE user_group_member (user_group_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (user_group_id, user_id))');
        $this->addSql('CREATE INDEX idx_user_group_member_group ON user_group_member (user_group_id)');
        $this->addSql('CREATE INDEX idx_user_group_member_user ON user_group_member (user_id)');
        $this->addSql('ALTER TABLE user_group_member ADD CONSTRAINT fk_user_group_member_group FOREIGN KEY (user_group_id) REFERENCES user_group (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_group_member ADD CONSTRAINT fk_user_group_member_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_group_member DROP CONSTRAINT fk_user_group_member_user');
        $this->addSql('ALTER TABLE user_group_member DROP CONSTRAINT fk_user_group_member_group');
        $this->addSql('DROP TABLE user_group_member');

        $this->addSql('ALTER TABLE user_group DROP CONSTRAINT fk_user_group_owner');
        $this->addSql('DROP TABLE user_group');
    }
}
