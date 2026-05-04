<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the discussion table for project-level discussion threads (#91).
 * Replies + chat channels land in follow-ups; this PR ships only the
 * top-level Discussion entity so the project-tabs UI can be built
 * against a stable surface first.
 */
final class Version20260504070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add discussion table for project-level discussion threads (#91).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE discussion (
                id UUID NOT NULL,
                project_id UUID NOT NULL,
                author_id UUID NOT NULL,
                title VARCHAR(200) NOT NULL,
                body TEXT NOT NULL,
                category VARCHAR(16) NOT NULL,
                is_pinned BOOLEAN NOT NULL DEFAULT FALSE,
                is_locked BOOLEAN NOT NULL DEFAULT FALSE,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_discussion_project_created ON discussion (project_id, created_at)');
        $this->addSql('CREATE INDEX idx_discussion_project_pinned ON discussion (project_id, is_pinned, created_at)');
        $this->addSql('ALTER TABLE discussion ADD CONSTRAINT FK_DISC_PROJECT FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE discussion ADD CONSTRAINT FK_DISC_AUTHOR FOREIGN KEY (author_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE discussion DROP CONSTRAINT FK_DISC_PROJECT');
        $this->addSql('ALTER TABLE discussion DROP CONSTRAINT FK_DISC_AUTHOR');
        $this->addSql('DROP TABLE discussion');
    }
}
