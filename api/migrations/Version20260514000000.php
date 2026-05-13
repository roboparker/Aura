<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Reparents attachments and discussions from `project` to `space`.
 *
 *   1. `discussion.project_id` is dropped — the denormalised `space_id`
 *      column (introduced in Version20260507120000) becomes the only
 *      parent. The two project-scoped indexes on `discussion` are
 *      replaced with space-scoped equivalents.
 *
 *   2. `project_attachment` is replaced by `space_attachment`. Existing
 *      rows are migrated by joining through `project.space_id`. The new
 *      join table has the same shape (composite PK, CASCADE on both
 *      sides). Duplicates (same media attached to multiple projects in
 *      the same space) collapse via ON CONFLICT DO NOTHING.
 *
 * The discussion FTS `search_vector` doesn't reference project_id so
 * the GIN index stays untouched.
 */
final class Version20260514000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reparent discussions + attachments from project to space.';
    }

    public function up(Schema $schema): void
    {
        // ----- Discussions -----------------------------------------

        // Drop project-scoped indexes — they reference project_id and
        // would block the column drop.
        $this->addSql('DROP INDEX IF EXISTS idx_discussion_project_created');
        $this->addSql('DROP INDEX IF EXISTS idx_discussion_project_pinned');

        // Drop the FK + column. space_id (already populated) becomes
        // the sole parent.
        $this->addSql(<<<'SQL'
            ALTER TABLE discussion
            DROP CONSTRAINT IF EXISTS fk_5b7be83b166d1f9c
        SQL);
        // Doctrine's actual constraint name is hashed; do a defensive
        // sweep based on column.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                fk_name TEXT;
            BEGIN
                FOR fk_name IN
                    SELECT conname FROM pg_constraint
                    WHERE conrelid = 'discussion'::regclass
                      AND contype = 'f'
                      AND conkey @> ARRAY[
                          (SELECT attnum FROM pg_attribute
                           WHERE attrelid = 'discussion'::regclass
                             AND attname = 'project_id')
                      ]
                LOOP
                    EXECUTE 'ALTER TABLE discussion DROP CONSTRAINT ' || quote_ident(fk_name);
                END LOOP;
            END$$
        SQL);
        $this->addSql('ALTER TABLE discussion DROP COLUMN project_id');

        // Drop the single-column `idx_discussion_space` from
        // Version20260507120000 — the composite indexes below subsume
        // it, and keeping it would show as an orphan in doctrine:
        // schema:validate against the entity mapping.
        $this->addSql('DROP INDEX IF EXISTS idx_discussion_space');

        // Re-add the two indexes scoped to space_id.
        $this->addSql('CREATE INDEX idx_discussion_space_created ON discussion (space_id, created_at)');
        $this->addSql('CREATE INDEX idx_discussion_space_pinned ON discussion (space_id, is_pinned, created_at)');

        // ----- Attachments -----------------------------------------

        $this->addSql(<<<'SQL'
            CREATE TABLE space_attachment (
                space_id UUID NOT NULL,
                media_object_id UUID NOT NULL,
                PRIMARY KEY(space_id, media_object_id)
            )
        SQL);
        // Index + FK names follow Doctrine's auto-generated convention
        // so doctrine:schema:validate matches the entity mapping with
        // no rename diffs.
        $this->addSql('CREATE INDEX IDX_7FC0CDEA23575340 ON space_attachment (space_id)');
        $this->addSql('CREATE INDEX IDX_7FC0CDEA64DE5A5 ON space_attachment (media_object_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE space_attachment
            ADD CONSTRAINT FK_7FC0CDEA23575340 FOREIGN KEY (space_id)
            REFERENCES space (id) ON DELETE CASCADE
            NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE space_attachment
            ADD CONSTRAINT FK_7FC0CDEA64DE5A5 FOREIGN KEY (media_object_id)
            REFERENCES media_object (id) ON DELETE CASCADE
            NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);

        // Backfill from project_attachment: every (project, media) pair
        // becomes (project.space, media). Collisions (same media on
        // multiple projects in the same space) collapse via the PK.
        $this->addSql(<<<'SQL'
            INSERT INTO space_attachment (space_id, media_object_id)
            SELECT DISTINCT p.space_id, pa.media_object_id
            FROM project_attachment pa
            INNER JOIN project p ON p.id = pa.project_id
            WHERE p.space_id IS NOT NULL
            ON CONFLICT DO NOTHING
        SQL);

        // Drop the old join table now that data has migrated.
        $this->addSql('DROP TABLE project_attachment');
    }

    public function down(Schema $schema): void
    {
        // ----- Attachments (reverse) -------------------------------

        $this->addSql(<<<'SQL'
            CREATE TABLE project_attachment (
                project_id UUID NOT NULL,
                media_object_id UUID NOT NULL,
                PRIMARY KEY(project_id, media_object_id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_61F9A289166D1F9C ON project_attachment (project_id)');
        $this->addSql('CREATE INDEX IDX_61F9A28964DE5A5 ON project_attachment (media_object_id)');
        $this->addSql('ALTER TABLE project_attachment ADD CONSTRAINT FK_61F9A289166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE project_attachment ADD CONSTRAINT FK_61F9A28964DE5A5 FOREIGN KEY (media_object_id) REFERENCES media_object (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Best-effort restoration: stamp every (space, media) onto
        // every project in that space. Pre-rename projects had the
        // exact pair so this re-creates a superset of the original
        // mapping — okay for a last-resort escape hatch.
        $this->addSql(<<<'SQL'
            INSERT INTO project_attachment (project_id, media_object_id)
            SELECT p.id, sa.media_object_id
            FROM space_attachment sa
            INNER JOIN project p ON p.space_id = sa.space_id
            ON CONFLICT DO NOTHING
        SQL);

        $this->addSql('DROP TABLE space_attachment');

        // ----- Discussions (reverse) -------------------------------

        $this->addSql('DROP INDEX IF EXISTS idx_discussion_space_created');
        $this->addSql('DROP INDEX IF EXISTS idx_discussion_space_pinned');

        // Re-add project_id nullable, backfill from the "first project
        // in the same space" so existing rows pass the eventual NOT
        // NULL — same best-effort caveat as the attachment fan-out.
        $this->addSql('ALTER TABLE discussion ADD COLUMN project_id UUID');
        $this->addSql(<<<'SQL'
            UPDATE discussion d
            SET project_id = (
                SELECT p.id FROM project p
                WHERE p.space_id = d.space_id
                ORDER BY p.created_on ASC
                LIMIT 1
            )
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE discussion
            ADD CONSTRAINT FK_5B7BE83B166D1F9C FOREIGN KEY (project_id)
            REFERENCES project (id) ON DELETE CASCADE
        SQL);
        // Restore the original indexes.
        $this->addSql('CREATE INDEX idx_discussion_project_created ON discussion (project_id, created_at)');
        $this->addSql('CREATE INDEX idx_discussion_project_pinned ON discussion (project_id, is_pinned, created_at)');
    }
}
