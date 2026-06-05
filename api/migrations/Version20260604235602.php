<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `discussion` as a third polymorphic parent for comments (#228
 * forum surface). Mirrors the existing task / page FKs: a nullable
 * `discussion_id` with cascade-on-delete plus the per-parent
 * created-at index. Widens `commentable_type` from VARCHAR(8) to
 * VARCHAR(16) since `'discussion'` (10 chars) overflows the old
 * width, and recreates `chk_comment_parent_exactly_one` so exactly
 * one of task / page / discussion is set on every row.
 */
final class Version20260604235602 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add discussion parent FK to comment; widen commentable_type; extend exactly-one CHECK.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comment ADD discussion_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE comment ALTER commentable_type TYPE VARCHAR(16)');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C1ADED311 FOREIGN KEY (discussion_id) REFERENCES discussion (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_9474526C1ADED311 ON comment (discussion_id)');
        $this->addSql('CREATE INDEX idx_comment_discussion_created ON comment (discussion_id, created_at)');

        // Extend the exactly-one-parent invariant to cover the new
        // discussion arm.
        $this->addSql('ALTER TABLE comment DROP CONSTRAINT chk_comment_parent_exactly_one');
        $this->addSql(<<<'SQL'
            ALTER TABLE comment
            ADD CONSTRAINT chk_comment_parent_exactly_one
            CHECK (
                (commentable_type = 'task' AND task_id IS NOT NULL AND page_id IS NULL AND discussion_id IS NULL)
                OR (commentable_type = 'page' AND page_id IS NOT NULL AND task_id IS NULL AND discussion_id IS NULL)
                OR (commentable_type = 'discussion' AND discussion_id IS NOT NULL AND task_id IS NULL AND page_id IS NULL)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comment DROP CONSTRAINT chk_comment_parent_exactly_one');
        $this->addSql(<<<'SQL'
            ALTER TABLE comment
            ADD CONSTRAINT chk_comment_parent_exactly_one
            CHECK (
                (commentable_type = 'task' AND task_id IS NOT NULL AND page_id IS NULL)
                OR (commentable_type = 'page' AND page_id IS NOT NULL AND task_id IS NULL)
            )
        SQL);

        $this->addSql('ALTER TABLE comment DROP CONSTRAINT FK_9474526C1ADED311');
        $this->addSql('DROP INDEX IDX_9474526C1ADED311');
        $this->addSql('DROP INDEX idx_comment_discussion_created');
        $this->addSql('ALTER TABLE comment DROP discussion_id');
        $this->addSql('ALTER TABLE comment ALTER commentable_type TYPE VARCHAR(8)');
    }
}
