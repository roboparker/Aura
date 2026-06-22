<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds `feedback` as a fourth polymorphic parent for comments, so the
 * in-app feedback board reuses the unified Comment thread (#228). Mirrors
 * the existing task / page / discussion FKs: a nullable `feedback_id`
 * with cascade-on-delete plus a per-parent created-at index. The
 * discriminator column is already VARCHAR(16) (`'feedback'` fits), so it
 * only recreates `chk_comment_parent_exactly_one` to cover the new arm.
 */
final class Version20260622195706 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feedback parent FK to comment; extend exactly-one-parent CHECK.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comment ADD feedback_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CD249A887 FOREIGN KEY (feedback_id) REFERENCES feedback (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_9474526CD249A887 ON comment (feedback_id)');
        $this->addSql('CREATE INDEX idx_comment_feedback_created ON comment (feedback_id, created_at)');

        // Extend the exactly-one-parent invariant to cover the new
        // feedback arm.
        $this->addSql('ALTER TABLE comment DROP CONSTRAINT chk_comment_parent_exactly_one');
        $this->addSql(<<<'SQL'
            ALTER TABLE comment
            ADD CONSTRAINT chk_comment_parent_exactly_one
            CHECK (
                (commentable_type = 'task' AND task_id IS NOT NULL AND page_id IS NULL AND discussion_id IS NULL AND feedback_id IS NULL)
                OR (commentable_type = 'page' AND page_id IS NOT NULL AND task_id IS NULL AND discussion_id IS NULL AND feedback_id IS NULL)
                OR (commentable_type = 'discussion' AND discussion_id IS NOT NULL AND task_id IS NULL AND page_id IS NULL AND feedback_id IS NULL)
                OR (commentable_type = 'feedback' AND feedback_id IS NOT NULL AND task_id IS NULL AND page_id IS NULL AND discussion_id IS NULL)
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
                (commentable_type = 'task' AND task_id IS NOT NULL AND page_id IS NULL AND discussion_id IS NULL)
                OR (commentable_type = 'page' AND page_id IS NOT NULL AND task_id IS NULL AND discussion_id IS NULL)
                OR (commentable_type = 'discussion' AND discussion_id IS NOT NULL AND task_id IS NULL AND page_id IS NULL)
            )
        SQL);

        $this->addSql('ALTER TABLE comment DROP CONSTRAINT FK_9474526CD249A887');
        $this->addSql('DROP INDEX IDX_9474526CD249A887');
        $this->addSql('DROP INDEX idx_comment_feedback_created');
        $this->addSql('ALTER TABLE comment DROP feedback_id');
    }
}
