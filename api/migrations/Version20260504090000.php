<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Postgres full-text search columns + GIN indexes to `task` and
 * `comment` so the search filter can switch from LIKE %q% to ranked
 * tsvector matching (#87).
 *
 * `search_vector` is a STORED generated column — Postgres recomputes it
 * on every insert/update without app-side bookkeeping. The English
 * configuration covers the bulk of our content; multilingual support
 * would need either a per-row config column or a swap to `simple` plus
 * caller-provided language.
 *
 * Title is weighted A and description B so a title match outranks a
 * description match for the same query, matching what users intuit
 * when both columns mention the term.
 */
final class Version20260504090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tsvector search_vector + GIN index to task and comment (#87).';
    }

    public function up(Schema $schema): void
    {
        // Task: title (A) + description (B). coalesce keeps null
        // descriptions from poisoning the concat.
        $this->addSql(<<<'SQL'
            ALTER TABLE task
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', title), 'A') ||
                setweight(to_tsvector('english', coalesce(description, '')), 'B')
            ) STORED
        SQL);
        $this->addSql('CREATE INDEX idx_task_search_vector ON task USING GIN (search_vector)');

        // Comment body searched separately so we can still use a
        // ranked match (vs. ILIKE) inside the EXISTS subquery in the
        // task search filter.
        $this->addSql(<<<'SQL'
            ALTER TABLE comment
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (to_tsvector('english', coalesce(body, ''))) STORED
        SQL);
        $this->addSql('CREATE INDEX idx_comment_search_vector ON comment USING GIN (search_vector)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_task_search_vector');
        $this->addSql('ALTER TABLE task DROP COLUMN search_vector');
        $this->addSql('DROP INDEX idx_comment_search_vector');
        $this->addSql('ALTER TABLE comment DROP COLUMN search_vector');
    }
}
