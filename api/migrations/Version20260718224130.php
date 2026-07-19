<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove the Discussion feature entirely (pre-release, no BC). Drops the
 * `discussion` table and the polymorphic discussion arm of `comment` +
 * `notification`:
 *
 *  - deletes discussion-parented comment rows and discussion-targeted
 *    notification rows,
 *  - drops + recreates the hand-written `chk_comment_parent_exactly_one`
 *    CHECK (raw SQL Doctrine's diff can't manage; it also blocks dropping
 *    `comment.discussion_id` until removed) so it only covers task / page /
 *    feedback,
 *  - drops the `discussion_id` FK/indexes/columns off `comment` and
 *    `notification`, then the `discussion` table itself.
 */
final class Version20260718224130 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove the Discussion feature: drop discussion table + comment/notification discussion arm.';
    }

    public function up(Schema $schema): void
    {
        // Purge discussion-scoped rows before the columns/constraint go.
        $this->addSql("DELETE FROM comment WHERE commentable_type = 'discussion'");
        $this->addSql('DELETE FROM notification WHERE discussion_id IS NOT NULL');

        // Drop the CHECK first — it depends on comment.discussion_id.
        $this->addSql('ALTER TABLE comment DROP CONSTRAINT chk_comment_parent_exactly_one');

        // Drop the FKs that reference discussion before dropping the table.
        $this->addSql('ALTER TABLE comment DROP CONSTRAINT fk_9474526c1aded311');
        $this->addSql('DROP INDEX idx_comment_discussion_created');
        $this->addSql('DROP INDEX idx_9474526c1aded311');
        $this->addSql('ALTER TABLE comment DROP discussion_id');

        $this->addSql('ALTER TABLE notification DROP CONSTRAINT fk_bf5476ca1aded311');
        $this->addSql('DROP INDEX idx_bf5476ca1aded311');
        $this->addSql('ALTER TABLE notification DROP discussion_id');

        // Now the table has no inbound FKs — drop it (and its own FKs).
        $this->addSql('ALTER TABLE discussion DROP CONSTRAINT fk_disc_author');
        $this->addSql('ALTER TABLE discussion DROP CONSTRAINT fk_discussion_space');
        $this->addSql('DROP TABLE discussion');

        // Recreate the exactly-one CHECK over the surviving parents.
        $this->addSql(
            'ALTER TABLE comment
                ADD CONSTRAINT chk_comment_parent_exactly_one
                CHECK (
                    (commentable_type = \'task\' AND task_id IS NOT NULL AND page_id IS NULL AND feedback_id IS NULL)
                    OR (commentable_type = \'page\' AND page_id IS NOT NULL AND task_id IS NULL AND feedback_id IS NULL)
                    OR (commentable_type = \'feedback\' AND feedback_id IS NOT NULL AND task_id IS NULL AND page_id IS NULL)
                )',
        );
    }

    public function down(Schema $schema): void
    {
        // Recreate the discussion table + its FKs/indexes. NOTE: search_vector
        // is recreated as a plain column here, not the STORED generated FTS
        // column of the original; this down path is a structural best-effort
        // (pre-release, never rolled back with data).
        $this->addSql('CREATE TABLE discussion (id UUID NOT NULL, author_id UUID NOT NULL, title VARCHAR(200) NOT NULL, body TEXT NOT NULL, category VARCHAR(16) NOT NULL, is_pinned BOOLEAN DEFAULT false NOT NULL, is_locked BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, search_vector TEXT DEFAULT NULL, space_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_discussion_space_created ON discussion (space_id, created_at)');
        $this->addSql('CREATE INDEX idx_discussion_search_vector ON discussion (search_vector)');
        $this->addSql('CREATE INDEX idx_discussion_space_pinned ON discussion (space_id, is_pinned, created_at)');
        $this->addSql('CREATE INDEX IDX_C0B9F90FF675F31B ON discussion (author_id)');
        $this->addSql('CREATE INDEX IDX_C0B9F90F23575340 ON discussion (space_id)');
        $this->addSql('ALTER TABLE discussion ADD CONSTRAINT fk_disc_author FOREIGN KEY (author_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE discussion ADD CONSTRAINT fk_discussion_space FOREIGN KEY (space_id) REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE comment DROP CONSTRAINT chk_comment_parent_exactly_one');
        $this->addSql('ALTER TABLE comment ADD discussion_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT fk_9474526c1aded311 FOREIGN KEY (discussion_id) REFERENCES discussion (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_comment_discussion_created ON comment (discussion_id, created_at)');
        $this->addSql('CREATE INDEX idx_9474526c1aded311 ON comment (discussion_id)');
        $this->addSql(
            'ALTER TABLE comment
                ADD CONSTRAINT chk_comment_parent_exactly_one
                CHECK (
                    (commentable_type = \'task\' AND task_id IS NOT NULL AND page_id IS NULL AND discussion_id IS NULL AND feedback_id IS NULL)
                    OR (commentable_type = \'page\' AND page_id IS NOT NULL AND task_id IS NULL AND discussion_id IS NULL AND feedback_id IS NULL)
                    OR (commentable_type = \'discussion\' AND discussion_id IS NOT NULL AND task_id IS NULL AND page_id IS NULL AND feedback_id IS NULL)
                    OR (commentable_type = \'feedback\' AND feedback_id IS NOT NULL AND task_id IS NULL AND page_id IS NULL AND discussion_id IS NULL)
                )',
        );

        $this->addSql('ALTER TABLE notification ADD discussion_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT fk_bf5476ca1aded311 FOREIGN KEY (discussion_id) REFERENCES discussion (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_bf5476ca1aded311 ON notification (discussion_id)');
    }
}
