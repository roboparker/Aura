<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds comment_id to notification (nullable, FK to comment with cascade
 * delete) so @mention rows can deep-link back to the comment that
 * triggered them. The (recipient, comment) unique constraint backs the
 * idempotency check in CommentMentionService — editing a comment to
 * keep an existing mention won't re-fire it.
 */
final class Version20260504040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add comment_id to notification for @mention notifications.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD comment_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAF8697D13 FOREIGN KEY (comment_id) REFERENCES comment (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_BF5476CAF8697D13 ON notification (comment_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_comment_mention ON notification (recipient_id, comment_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_comment_mention');
        $this->addSql('DROP INDEX IDX_BF5476CAF8697D13');
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT FK_BF5476CAF8697D13');
        $this->addSql('ALTER TABLE notification DROP comment_id');
    }
}
