<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds Postgres full-text search columns + GIN indexes to `project`
 * and `discussion` so the existing task-only search filter pattern can
 * be extended to those resources too (#157).
 *
 * `search_vector` is a STORED generated column — Postgres recomputes
 * it on every insert/update without app-side bookkeeping. Title is
 * weighted A and body/description B so a title hit outranks a body
 * hit at equal frequencies, matching what users intuit when both
 * columns mention the term.
 */
final class Version20260506010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tsvector search_vector + GIN index to project and discussion (#157).';
    }

    public function up(Schema $schema): void
    {
        // Project: title (A) + description (B). coalesce keeps a null
        // description from poisoning the concat.
        $this->addSql(<<<'SQL'
            ALTER TABLE project
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', title), 'A') ||
                setweight(to_tsvector('english', coalesce(description, '')), 'B')
            ) STORED
        SQL);
        $this->addSql('CREATE INDEX idx_project_search_vector ON project USING GIN (search_vector)');

        // Discussion: title (A) + body (B). body is NOT NULL on this
        // entity but coalesce is harmless and keeps the expression in
        // line with project / task.
        $this->addSql(<<<'SQL'
            ALTER TABLE discussion
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', title), 'A') ||
                setweight(to_tsvector('english', coalesce(body, '')), 'B')
            ) STORED
        SQL);
        $this->addSql('CREATE INDEX idx_discussion_search_vector ON discussion USING GIN (search_vector)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_project_search_vector');
        $this->addSql('ALTER TABLE project DROP COLUMN search_vector');
        $this->addSql('DROP INDEX idx_discussion_search_vector');
        $this->addSql('ALTER TABLE discussion DROP COLUMN search_vector');
    }
}
