<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Unifies `task_comment` + `page_comment` into one `comment` table (#228).
 *
 * The two tables had converged on the same shape (flat thread, @mentions,
 * author/body/created/updated) and the only remaining differences were
 * which parent FK they carried. We keep both parent FKs (`task_id` and
 * `page_id`) on the unified table — exactly one is set per row, with a
 * check constraint to enforce that — so cascade-on-delete from each
 * parent type still works without a polymorphic untyped column.
 *
 * Steps (single transaction, reversible):
 *
 *   1. Extend `task_comment`:
 *      - Add `commentable_type VARCHAR(8) NULL` (NOT NULL after backfill).
 *      - Add `page_id UUID NULL` + FK to `page` ON DELETE CASCADE.
 *      - Backfill `commentable_type = 'task'` for every existing row.
 *
 *   2. Flatten the reply tree. The old `parent_comment_id` self-FK
 *      supported up to 3 levels of nesting; #228 collapses to flat
 *      chronological. Just drop the FK + column — replies stay as
 *      standalone rows ordered by `created_at` (their context lives in
 *      the chronological view).
 *
 *   3. Make `task_id` nullable so page-parent rows can leave it empty.
 *
 *   4. Migrate `page_comment` rows in: `INSERT INTO task_comment (id,
 *      page_id, author_id, body, created_at, updated_at,
 *      commentable_type) SELECT ..., 'page' FROM page_comment`.
 *
 *   5. Migrate notifications: `task_mention` → `mention` so the type
 *      column matches the new unified naming.
 *
 *   6. Rename the table `task_comment` → `comment`. Rename the
 *      Doctrine-generated FK/index names so the crc32(table) prefix
 *      matches the new table (`8B957886` → `9474526C`).
 *
 *   7. Drop the now-empty `page_comment` table.
 *
 *   8. Tighten:
 *      - `commentable_type` NOT NULL.
 *      - CHECK constraint: exactly one of `task_id` / `page_id` is set,
 *        and `commentable_type` matches.
 *      - Add `idx_comment_page_created (page_id, created_at)` companion
 *        to the existing task index.
 */
final class Version20260515000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Unify task_comment + page_comment into one polymorphic comment table (#228).';
    }

    public function up(Schema $schema): void
    {
        // 1. New columns on task_comment.
        $this->addSql('ALTER TABLE task_comment ADD COLUMN commentable_type VARCHAR(8)');
        $this->addSql('ALTER TABLE task_comment ADD COLUMN page_id UUID DEFAULT NULL');
        $this->addSql("UPDATE task_comment SET commentable_type = 'task'");
        $this->addSql('ALTER TABLE task_comment ADD CONSTRAINT FK_8B957886C4663E4 FOREIGN KEY (page_id) REFERENCES page (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        // Postgres doesn't auto-create an index for new FKs; Doctrine
        // expects one matching the constraint hash on every ManyToOne
        // join column. Create it explicitly to keep
        // `doctrine:schema:validate` clean after the rename.
        $this->addSql('CREATE INDEX IDX_8B957886C4663E4 ON task_comment (page_id)');

        // 2. Flatten the reply tree.
        $this->addSql('ALTER TABLE task_comment DROP CONSTRAINT FK_8B957886BF2AF943');
        $this->addSql('DROP INDEX idx_task_comment_parent');
        $this->addSql('ALTER TABLE task_comment DROP COLUMN parent_comment_id');

        // 3. task_id becomes nullable so page rows can sit alongside.
        $this->addSql('ALTER TABLE task_comment ALTER COLUMN task_id DROP NOT NULL');

        // 4. Move page_comment rows over.
        $this->addSql(<<<'SQL'
            INSERT INTO task_comment (id, page_id, author_id, body, created_at, updated_at, commentable_type)
            SELECT id, page_id, author_id, body, created_at, updated_at, 'page'
            FROM page_comment
        SQL);

        // 5. Notification type rename.
        $this->addSql("UPDATE notification SET type = 'mention' WHERE type = 'task_mention'");

        // 6. Rename the table + every Doctrine-hashed name attached to it.
        //
        //    crc32('task_comment') = 8B957886 (lowercase in the catalog
        //    because the original CREATE INDEX was unquoted)
        //    crc32('comment')      = 9474526C
        $this->addSql('ALTER TABLE task_comment RENAME TO comment');
        $this->addSql('ALTER INDEX task_comment_pkey RENAME TO comment_pkey');
        $this->addSql('ALTER INDEX idx_task_comment_task_created RENAME TO idx_comment_task_created');
        $this->addSql('ALTER INDEX idx_task_comment_search_vector RENAME TO idx_comment_search_vector');
        $this->addSql('ALTER INDEX IDX_8B9578868DB60186 RENAME TO IDX_9474526C8DB60186');
        $this->addSql('ALTER INDEX IDX_8B957886F675F31B RENAME TO IDX_9474526CF675F31B');
        $this->addSql('ALTER INDEX IDX_8B957886C4663E4 RENAME TO IDX_9474526CC4663E4');
        $this->addSql('ALTER TABLE comment RENAME CONSTRAINT FK_8B9578868DB60186 TO FK_9474526C8DB60186');
        $this->addSql('ALTER TABLE comment RENAME CONSTRAINT FK_8B957886F675F31B TO FK_9474526CF675F31B');
        $this->addSql('ALTER TABLE comment RENAME CONSTRAINT FK_8B957886C4663E4 TO FK_9474526CC4663E4');

        // 7. Drop the source table.
        $this->addSql('DROP TABLE page_comment');

        // 8. Tighten + index.
        $this->addSql('ALTER TABLE comment ALTER COLUMN commentable_type SET NOT NULL');
        $this->addSql('CREATE INDEX idx_comment_page_created ON comment (page_id, created_at)');
        $this->addSql(<<<'SQL'
            ALTER TABLE comment
            ADD CONSTRAINT chk_comment_parent_exactly_one
            CHECK (
                (commentable_type = 'task' AND task_id IS NOT NULL AND page_id IS NULL)
                OR (commentable_type = 'page' AND page_id IS NOT NULL AND task_id IS NULL)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Reverse step 8.
        $this->addSql('ALTER TABLE comment DROP CONSTRAINT chk_comment_parent_exactly_one');
        $this->addSql('DROP INDEX idx_comment_page_created');
        $this->addSql('ALTER TABLE comment ALTER COLUMN commentable_type DROP NOT NULL');

        // Reverse step 7 — recreate page_comment.
        $this->addSql(<<<'SQL'
            CREATE TABLE page_comment (
                id UUID NOT NULL,
                page_id UUID NOT NULL,
                author_id UUID NOT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_page_comment_page_created ON page_comment (page_id, created_at)');
        $this->addSql('ALTER TABLE page_comment ADD CONSTRAINT fk_page_comment_page FOREIGN KEY (page_id) REFERENCES page(id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE page_comment ADD CONSTRAINT fk_page_comment_author FOREIGN KEY (author_id) REFERENCES "user"(id) ON DELETE CASCADE');

        // Reverse step 6 — rename back. Notification.comment_id FK
        // implicitly follows the table rename.
        $this->addSql('ALTER TABLE comment RENAME CONSTRAINT FK_9474526CC4663E4 TO FK_8B957886C4663E4');
        $this->addSql('ALTER TABLE comment RENAME CONSTRAINT FK_9474526CF675F31B TO FK_8B957886F675F31B');
        $this->addSql('ALTER TABLE comment RENAME CONSTRAINT FK_9474526C8DB60186 TO FK_8B9578868DB60186');
        $this->addSql('ALTER INDEX IDX_9474526CC4663E4 RENAME TO IDX_8B957886C4663E4');
        $this->addSql('ALTER INDEX IDX_9474526CF675F31B RENAME TO IDX_8B957886F675F31B');
        $this->addSql('ALTER INDEX IDX_9474526C8DB60186 RENAME TO IDX_8B9578868DB60186');
        $this->addSql('ALTER INDEX idx_comment_search_vector RENAME TO idx_task_comment_search_vector');
        $this->addSql('ALTER INDEX idx_comment_task_created RENAME TO idx_task_comment_task_created');
        $this->addSql('ALTER INDEX comment_pkey RENAME TO task_comment_pkey');
        $this->addSql('ALTER TABLE comment RENAME TO task_comment');

        // Reverse step 5 — restore the old notification type name.
        $this->addSql("UPDATE notification SET type = 'task_mention' WHERE type = 'mention'");

        // Reverse step 4 — move page-parent rows back out.
        $this->addSql(<<<'SQL'
            INSERT INTO page_comment (id, page_id, author_id, body, created_at, updated_at)
            SELECT id, page_id, author_id, body, created_at, updated_at
            FROM task_comment
            WHERE commentable_type = 'page'
        SQL);
        $this->addSql("DELETE FROM task_comment WHERE commentable_type = 'page'");

        // Reverse step 3.
        $this->addSql('ALTER TABLE task_comment ALTER COLUMN task_id SET NOT NULL');

        // Reverse step 2. Restore parent_comment_id without data — the
        // flatten step in up() lost the parent references and we can't
        // reconstruct them.
        $this->addSql('ALTER TABLE task_comment ADD COLUMN parent_comment_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_task_comment_parent ON task_comment (parent_comment_id)');
        $this->addSql('ALTER TABLE task_comment ADD CONSTRAINT FK_8B957886BF2AF943 FOREIGN KEY (parent_comment_id) REFERENCES task_comment(id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Reverse step 1.
        $this->addSql('DROP INDEX IDX_8B957886C4663E4');
        $this->addSql('ALTER TABLE task_comment DROP CONSTRAINT FK_8B957886C4663E4');
        $this->addSql('ALTER TABLE task_comment DROP COLUMN page_id');
        $this->addSql('ALTER TABLE task_comment DROP COLUMN commentable_type');
    }
}
