<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Re-scope groups from user-owned to space-owned (#groups-space).
 *
 * A `UserGroup` now belongs to exactly one `Space` (the `space_id` FK replaces
 * the old `owner_id`), and grants its members access to that one space — so the
 * `space_group_membership` join table (which allowed a group to be attached to
 * many spaces) is dropped. Steps:
 *
 *   1. add nullable `user_group.space_id`,
 *   2. backfill it from the group's existing attached space (personal first,
 *      then earliest joined), else the owner's personal space,
 *   3. `SET NOT NULL` (acts as the guard — fails loudly if any row is unfilled),
 *      add the index + FK,
 *   4. drop `space_group_membership`, then drop `user_group.owner_id`.
 *
 * Irreversible bits: groups attached to MORE than one space keep only one; the
 * old `owner` is not recoverable. Back up before running.
 */
final class Version20260627120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope groups to a single space (user_group.space_id replaces owner_id; drop space_group_membership).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_group ADD COLUMN space_id UUID DEFAULT NULL');

        // Prefer the group's existing attached space — personal space first,
        // then the earliest-joined attachment for a deterministic pick.
        $this->addSql(<<<'SQL'
            UPDATE user_group g
            SET space_id = (
                SELECT sgm.space_id
                FROM space_group_membership sgm
                JOIN space sp ON sp.id = sgm.space_id
                WHERE sgm.user_group_id = g.id
                ORDER BY sp.is_personal DESC, sgm.joined_at ASC
                LIMIT 1
            )
            WHERE g.space_id IS NULL
              AND EXISTS (SELECT 1 FROM space_group_membership s2 WHERE s2.user_group_id = g.id)
        SQL);

        // Otherwise fall back to the owner's personal space.
        $this->addSql(<<<'SQL'
            UPDATE user_group g
            SET space_id = s.id
            FROM space s
            WHERE g.space_id IS NULL
              AND s.created_by_id = g.owner_id
              AND s.is_personal = TRUE
        SQL);

        // Guard: fails the migration if any group is still unscoped.
        $this->addSql('ALTER TABLE user_group ALTER COLUMN space_id SET NOT NULL');
        $this->addSql('CREATE INDEX idx_user_group_space ON user_group (space_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE user_group
            ADD CONSTRAINT fk_user_group_space
            FOREIGN KEY (space_id) REFERENCES space (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);

        // The join table is obsolete — a group's single space is its FK now.
        $this->addSql('DROP TABLE space_group_membership');

        // Drop the old owner parent.
        $this->addSql('ALTER TABLE user_group DROP CONSTRAINT fk_user_group_owner');
        $this->addSql('DROP INDEX idx_user_group_owner');
        $this->addSql('ALTER TABLE user_group DROP COLUMN owner_id');
    }

    public function down(Schema $schema): void
    {
        // Best-effort schema rollback. Owner data is irrecoverable: re-add a
        // nullable owner_id (left NULL) and recreate the join table empty.
        $this->addSql('ALTER TABLE user_group ADD COLUMN owner_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_user_group_owner ON user_group (owner_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE user_group
            ADD CONSTRAINT fk_user_group_owner
            FOREIGN KEY (owner_id) REFERENCES "user" (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE space_group_membership (
                id UUID NOT NULL,
                space_id UUID NOT NULL,
                user_group_id UUID NOT NULL,
                role VARCHAR(16) DEFAULT 'member' NOT NULL,
                joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_space_group_membership ON space_group_membership (space_id, user_group_id)');
        $this->addSql('CREATE INDEX idx_space_group_membership_group ON space_group_membership (user_group_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE space_group_membership
            ADD CONSTRAINT fk_space_group_membership_space
            FOREIGN KEY (space_id) REFERENCES space (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE space_group_membership
            ADD CONSTRAINT fk_space_group_membership_group
            FOREIGN KEY (user_group_id) REFERENCES user_group (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);

        $this->addSql('ALTER TABLE user_group DROP CONSTRAINT fk_user_group_space');
        $this->addSql('DROP INDEX idx_user_group_space');
        $this->addSql('ALTER TABLE user_group DROP COLUMN space_id');
    }
}
