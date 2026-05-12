<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renames `comment` -> `task_comment` to match the renamed `App\Entity\TaskComment`
 * class. Discussions and pages have their own comment tables (`page_comment`,
 * etc.), so the unprefixed `comment` was ambiguous; the rename makes the
 * task-scope explicit at the data layer.
 *
 * Index names follow the table prefix. FK constraints use hash-based names so
 * they don't need touching. The STORED `search_vector` generated column moves
 * with the table since generated columns reference column names, not the table
 * itself.
 */
final class Version20260512000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename comment table to task_comment (entity renamed to TaskComment).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comment RENAME TO task_comment');
        $this->addSql('ALTER INDEX comment_pkey RENAME TO task_comment_pkey');
        $this->addSql('ALTER INDEX idx_comment_task_created RENAME TO idx_task_comment_task_created');
        $this->addSql('ALTER INDEX idx_comment_parent RENAME TO idx_task_comment_parent');
        $this->addSql('ALTER INDEX idx_comment_search_vector RENAME TO idx_task_comment_search_vector');
        // The auto-generated indexes from CREATE TABLE / FK declarations
        // (IDX_9474526C8DB60186 on task_id, IDX_9474526CF675F31B on author_id,
        // IDX_9474526CBF2AF943 on parent_comment_id) keep their hash names —
        // they're table-internal and Postgres doesn't include the table
        // prefix in their identity.
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_task_comment_search_vector RENAME TO idx_comment_search_vector');
        $this->addSql('ALTER INDEX idx_task_comment_parent RENAME TO idx_comment_parent');
        $this->addSql('ALTER INDEX idx_task_comment_task_created RENAME TO idx_comment_task_created');
        $this->addSql('ALTER INDEX task_comment_pkey RENAME TO comment_pkey');
        $this->addSql('ALTER TABLE task_comment RENAME TO comment');
    }
}
