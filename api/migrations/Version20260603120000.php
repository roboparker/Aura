<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Group styling foundation.
 *
 * Adds `slug`, `color`, and `updated_at` to `user_group`, and replaces
 * the plain `user_group_member` ManyToMany join table with a first-class
 * `user_group_membership` entity carrying a `joined_at` timestamp (so the
 * group detail page can show when each member joined).
 *
 * Backfills:
 *   - `updated_at` ← `created_on`
 *   - `slug` ← slugified `title`, deduped with an incrementing suffix
 *   - membership rows ← existing `user_group_member` pairs, with
 *     `joined_at` seeded to the group's `created_on`
 *
 * `gen_random_uuid()` is core in Postgres ≥13, so no extension is needed.
 */
final class Version20260603120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add UserGroup slug/color/updatedAt and convert members to a UserGroupMembership join entity.';
    }

    public function up(Schema $schema): void
    {
        // --- New user_group columns ------------------------------------
        $this->addSql('ALTER TABLE user_group ADD slug VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_group ADD color VARCHAR(7) DEFAULT NULL');
        $this->addSql('ALTER TABLE user_group ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // Seed updated_at from the existing creation timestamp.
        $this->addSql('UPDATE user_group SET updated_at = created_on WHERE updated_at IS NULL');

        // Backfill slug: slugify the title, fall back to "group" when the
        // title has no alphanumerics, then disambiguate collisions with an
        // incrementing suffix (-2, -3, …) keyed on the slug base.
        $this->addSql(<<<'SQL'
            WITH base AS (
                SELECT
                    id,
                    COALESCE(
                        NULLIF(trim(BOTH '-' FROM regexp_replace(lower(title), '[^a-z0-9]+', '-', 'g')), ''),
                        'group'
                    ) AS slug_base
                FROM user_group
            ),
            numbered AS (
                SELECT
                    id,
                    slug_base,
                    row_number() OVER (PARTITION BY slug_base ORDER BY id) AS rn
                FROM base
            )
            UPDATE user_group ug
            SET slug = CASE
                WHEN n.rn = 1 THEN left(n.slug_base, 50)
                ELSE left(n.slug_base, 46) || '-' || n.rn
            END
            FROM numbered n
            WHERE ug.id = n.id
        SQL);

        $this->addSql('ALTER TABLE user_group ALTER COLUMN slug SET NOT NULL');
        $this->addSql('ALTER TABLE user_group ALTER COLUMN updated_at SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_group_slug ON user_group (slug)');

        // --- New user_group_membership table ---------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE user_group_membership (
                id UUID NOT NULL,
                user_group_id UUID NOT NULL,
                user_id UUID NOT NULL,
                joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_user_group_membership_user ON user_group_membership (user_group_id, user_id)');
        $this->addSql('CREATE INDEX idx_user_group_membership_user ON user_group_membership (user_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE user_group_membership
            ADD CONSTRAINT fk_user_group_membership_group
            FOREIGN KEY (user_group_id) REFERENCES user_group (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE user_group_membership
            ADD CONSTRAINT fk_user_group_membership_user
            FOREIGN KEY (user_id) REFERENCES "user" (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);

        // Backfill membership rows from the old join table, seeding
        // joined_at to the group's creation time.
        $this->addSql(<<<'SQL'
            INSERT INTO user_group_membership (id, user_group_id, user_id, joined_at)
            SELECT gen_random_uuid(), m.user_group_id, m.user_id, ug.created_on
            FROM user_group_member m
            JOIN user_group ug ON ug.id = m.user_group_id
        SQL);

        $this->addSql('DROP TABLE user_group_member');
    }

    public function down(Schema $schema): void
    {
        // Recreate the old ManyToMany join table and backfill it from the
        // membership rows before dropping the new structures.
        $this->addSql(<<<'SQL'
            CREATE TABLE user_group_member (
                user_group_id UUID NOT NULL,
                user_id UUID NOT NULL,
                PRIMARY KEY(user_group_id, user_id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_user_group_member_group ON user_group_member (user_group_id)');
        $this->addSql('CREATE INDEX idx_user_group_member_user ON user_group_member (user_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE user_group_member
            ADD CONSTRAINT fk_user_group_member_group
            FOREIGN KEY (user_group_id) REFERENCES user_group (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE user_group_member
            ADD CONSTRAINT fk_user_group_member_user
            FOREIGN KEY (user_id) REFERENCES "user" (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO user_group_member (user_group_id, user_id)
            SELECT user_group_id, user_id FROM user_group_membership
        SQL);

        $this->addSql('DROP TABLE user_group_membership');

        $this->addSql('DROP INDEX uniq_user_group_slug');
        $this->addSql('ALTER TABLE user_group DROP slug');
        $this->addSql('ALTER TABLE user_group DROP color');
        $this->addSql('ALTER TABLE user_group DROP updated_at');
    }
}
