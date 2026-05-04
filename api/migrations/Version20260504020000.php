<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds parent_comment_id to comment for threaded replies (see #105). The FK
 * cascades on delete so removing a parent drops its subtree in one shot;
 * depth is bounded in the entity validator (Comment::MAX_DEPTH) so the
 * cascade stays small.
 */
final class Version20260504020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add parent_comment_id to comment for threaded replies.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comment ADD parent_comment_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CBF2AF943 FOREIGN KEY (parent_comment_id) REFERENCES comment (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_comment_parent ON comment (parent_comment_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comment DROP CONSTRAINT FK_9474526CBF2AF943');
        $this->addSql('DROP INDEX idx_comment_parent');
        $this->addSql('ALTER TABLE comment DROP parent_comment_id');
    }
}
