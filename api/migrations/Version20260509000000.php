<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the `page` and `page_comment` tables (#183).
 *
 * `page.search_vector` is a STORED tsvector generated column over
 * `title` (weight A) + `body` (weight B), GIN-indexed — same shape as
 * Project/Discussion/Task in Version20260506010000. coalesce() guards
 * against null body even though the column is NOT NULL on the entity,
 * mirroring the existing pattern.
 */
final class Version20260509000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add page + page_comment tables with tsvector + GIN search (#183).';
    }

    public function up(Schema $schema): void
    {
        // page ---------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE page (
                id UUID NOT NULL,
                space_id UUID NOT NULL,
                parent_id UUID DEFAULT NULL,
                created_by_id UUID NOT NULL,
                title VARCHAR(200) NOT NULL,
                body TEXT NOT NULL,
                position INT DEFAULT 0 NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_page_space ON page (space_id)');
        $this->addSql('CREATE INDEX idx_page_parent ON page (parent_id)');
        $this->addSql('CREATE INDEX idx_page_space_tree ON page (space_id, parent_id, position)');

        $this->addSql(<<<'SQL'
            ALTER TABLE page
            ADD CONSTRAINT fk_page_space
            FOREIGN KEY (space_id) REFERENCES space (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE page
            ADD CONSTRAINT fk_page_parent
            FOREIGN KEY (parent_id) REFERENCES page (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE page
            ADD CONSTRAINT fk_page_created_by
            FOREIGN KEY (created_by_id) REFERENCES "user" (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);

        // search_vector + GIN index ----------------------------------
        $this->addSql(<<<'SQL'
            ALTER TABLE page
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', title), 'A') ||
                setweight(to_tsvector('english', coalesce(body, '')), 'B')
            ) STORED
        SQL);
        $this->addSql('CREATE INDEX idx_page_search_vector ON page USING GIN (search_vector)');

        // page_comment -----------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE page_comment (
                id UUID NOT NULL,
                page_id UUID NOT NULL,
                author_id UUID NOT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_page_comment_page_created ON page_comment (page_id, created_at)');

        $this->addSql(<<<'SQL'
            ALTER TABLE page_comment
            ADD CONSTRAINT fk_page_comment_page
            FOREIGN KEY (page_id) REFERENCES page (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE page_comment
            ADD CONSTRAINT fk_page_comment_author
            FOREIGN KEY (author_id) REFERENCES "user" (id)
            ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE page_comment');
        $this->addSql('DROP INDEX idx_page_search_vector');
        $this->addSql('DROP TABLE page');
    }
}
