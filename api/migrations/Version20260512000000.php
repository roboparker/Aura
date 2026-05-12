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
 * Renames the explicit indexes (idx_*), the primary key index, the two
 * Doctrine-hashed indexes on task_id / author_id, and all three FK
 * constraints. Doctrine's identifier generator hashes the table name into
 * IDX_/FK_ names (`dechex(crc32(table)) . dechex(crc32(column))`), so the
 * post-rename names switch from the `9474526C` prefix (crc32 of `comment`)
 * to `8B957886` (crc32 of `task_comment`); leaving them as-is makes
 * `doctrine:schema:validate` fail. The STORED `search_vector` generated
 * column moves with the table since generated columns reference column
 * names, not the table itself.
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
        // Doctrine-hashed indexes on task_id / author_id (rehashed against
        // the new table name).
        $this->addSql('ALTER INDEX "IDX_9474526C8DB60186" RENAME TO "IDX_8B9578868DB60186"');
        $this->addSql('ALTER INDEX "IDX_9474526CF675F31B" RENAME TO "IDX_8B957886F675F31B"');
        // FK constraints — task_id, author_id, parent_comment_id.
        $this->addSql('ALTER TABLE task_comment RENAME CONSTRAINT "FK_9474526C8DB60186" TO "FK_8B9578868DB60186"');
        $this->addSql('ALTER TABLE task_comment RENAME CONSTRAINT "FK_9474526CF675F31B" TO "FK_8B957886F675F31B"');
        $this->addSql('ALTER TABLE task_comment RENAME CONSTRAINT "FK_9474526CBF2AF943" TO "FK_8B957886BF2AF943"');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_comment RENAME CONSTRAINT "FK_8B957886BF2AF943" TO "FK_9474526CBF2AF943"');
        $this->addSql('ALTER TABLE task_comment RENAME CONSTRAINT "FK_8B957886F675F31B" TO "FK_9474526CF675F31B"');
        $this->addSql('ALTER TABLE task_comment RENAME CONSTRAINT "FK_8B9578868DB60186" TO "FK_9474526C8DB60186"');
        $this->addSql('ALTER INDEX "IDX_8B957886F675F31B" RENAME TO "IDX_9474526CF675F31B"');
        $this->addSql('ALTER INDEX "IDX_8B9578868DB60186" RENAME TO "IDX_9474526C8DB60186"');
        $this->addSql('ALTER INDEX idx_task_comment_search_vector RENAME TO idx_comment_search_vector');
        $this->addSql('ALTER INDEX idx_task_comment_parent RENAME TO idx_comment_parent');
        $this->addSql('ALTER INDEX idx_task_comment_task_created RENAME TO idx_comment_task_created');
        $this->addSql('ALTER INDEX task_comment_pkey RENAME TO comment_pkey');
        $this->addSql('ALTER TABLE task_comment RENAME TO comment');
    }
}
