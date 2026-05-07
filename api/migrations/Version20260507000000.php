<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Spaces foundation (#181).
 *
 * Creates the new `space`, `space_membership`, `space_group_membership`
 * tables, links existing `project` / `discussion` /
 * `custom_field_definition` rows to spaces, and provisions one
 * personal "Private" space per existing user with that user as the
 * sole admin.
 *
 * Behavior is unchanged for end users — owner-based access
 * predicates still gate Project/Discussion/CFD operations. PR 2
 * (#185) is the one that swaps in space-membership-based access.
 *
 * `gen_random_uuid()` is available in core Postgres ≥13, so no
 * CREATE EXTENSION is needed on Postgres 16. The backfill uses a
 * temporary mapping table (`tmp_user_personal_space`) to keep the
 * three foreign-key updates simple — JOINs against it instead of
 * recomputing the lookup three times.
 */
final class Version20260507000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Space, SpaceMembership, SpaceGroupMembership; backfill personal spaces and link existing Project/Discussion/CustomFieldDefinition (#181).';
    }

    public function up(Schema $schema): void
    {
        // --- New tables -------------------------------------------------

        $this->addSql(<<<'SQL'
            CREATE TABLE space (
                id UUID NOT NULL,
                created_by_id UUID DEFAULT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT DEFAULT NULL,
                is_personal BOOLEAN DEFAULT FALSE NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_space_created_by ON space (created_by_id)');
        // Partial unique index — at most one personal space per user.
        // NULLs from later "creator deleted" hand-offs don't conflict
        // (Postgres treats them as distinct), so SET NULL on the FK
        // remains safe.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_space_personal_per_user
            ON space (created_by_id)
            WHERE is_personal = TRUE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE space
            ADD CONSTRAINT fk_space_created_by
            FOREIGN KEY (created_by_id) REFERENCES "user" (id)
            ON DELETE SET NULL
            NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE space_membership (
                id UUID NOT NULL,
                space_id UUID NOT NULL,
                user_id UUID NOT NULL,
                role VARCHAR(16) DEFAULT 'member' NOT NULL,
                joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_space_membership_user ON space_membership (space_id, user_id)');
        $this->addSql('CREATE INDEX idx_space_membership_user ON space_membership (user_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE space_membership
            ADD CONSTRAINT fk_space_membership_space
            FOREIGN KEY (space_id) REFERENCES space (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE space_membership
            ADD CONSTRAINT fk_space_membership_user
            FOREIGN KEY (user_id) REFERENCES "user" (id)
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

        // --- Backfill: one personal "Private" space per user ----------

        // Temporary lookup table joining each user to their newly-minted
        // personal space, so the three FK backfills below stay simple.
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE tmp_user_personal_space (
                user_id UUID PRIMARY KEY,
                space_id UUID NOT NULL
            ) ON COMMIT DROP
        SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO tmp_user_personal_space (user_id, space_id)
            SELECT id, gen_random_uuid() FROM "user"
        SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO space (id, created_by_id, name, description, is_personal, created_at)
            SELECT t.space_id, t.user_id, 'Private', NULL, TRUE, NOW()
            FROM tmp_user_personal_space t
        SQL);

        $this->addSql(<<<'SQL'
            INSERT INTO space_membership (id, space_id, user_id, role, joined_at)
            SELECT gen_random_uuid(), t.space_id, t.user_id, 'admin', NOW()
            FROM tmp_user_personal_space t
        SQL);

        // --- Add space_id columns + backfill from project ownership ---

        $this->addSql('ALTER TABLE project ADD COLUMN space_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE discussion ADD COLUMN space_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE custom_field_definition ADD COLUMN space_id UUID DEFAULT NULL');

        // Project: route each project into its owner's personal space.
        $this->addSql(<<<'SQL'
            UPDATE project p
            SET space_id = t.space_id
            FROM tmp_user_personal_space t
            WHERE p.owner_id = t.user_id
        SQL);

        // Discussion: inherit from the parent project's space.
        $this->addSql(<<<'SQL'
            UPDATE discussion d
            SET space_id = p.space_id
            FROM project p
            WHERE d.project_id = p.id
        SQL);

        // Custom field definitions: same — inherit from project.
        $this->addSql(<<<'SQL'
            UPDATE custom_field_definition c
            SET space_id = p.space_id
            FROM project p
            WHERE c.project_id = p.id
        SQL);

        // --- Flip space_id to NOT NULL + add FKs ---------------------

        $this->addSql('ALTER TABLE project ALTER COLUMN space_id SET NOT NULL');
        $this->addSql('ALTER TABLE discussion ALTER COLUMN space_id SET NOT NULL');
        $this->addSql('ALTER TABLE custom_field_definition ALTER COLUMN space_id SET NOT NULL');

        $this->addSql('CREATE INDEX idx_project_space ON project (space_id)');
        $this->addSql('CREATE INDEX idx_discussion_space ON discussion (space_id)');
        $this->addSql('CREATE INDEX idx_cfd_space ON custom_field_definition (space_id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE project
            ADD CONSTRAINT fk_project_space
            FOREIGN KEY (space_id) REFERENCES space (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE discussion
            ADD CONSTRAINT fk_discussion_space
            FOREIGN KEY (space_id) REFERENCES space (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE custom_field_definition
            ADD CONSTRAINT fk_cfd_space
            FOREIGN KEY (space_id) REFERENCES space (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE custom_field_definition DROP CONSTRAINT fk_cfd_space');
        $this->addSql('ALTER TABLE discussion DROP CONSTRAINT fk_discussion_space');
        $this->addSql('ALTER TABLE project DROP CONSTRAINT fk_project_space');

        $this->addSql('DROP INDEX idx_cfd_space');
        $this->addSql('DROP INDEX idx_discussion_space');
        $this->addSql('DROP INDEX idx_project_space');

        $this->addSql('ALTER TABLE custom_field_definition DROP COLUMN space_id');
        $this->addSql('ALTER TABLE discussion DROP COLUMN space_id');
        $this->addSql('ALTER TABLE project DROP COLUMN space_id');

        $this->addSql('DROP TABLE space_group_membership');
        $this->addSql('DROP TABLE space_membership');
        $this->addSql('DROP TABLE space');
    }
}
