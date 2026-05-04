<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the project_attachment join table for the Project↔MediaObject
 * many-to-many. Mirrors task_attachment: both FKs cascade-delete so the
 * join row goes away with either side.
 */
final class Version20260503210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project_attachment join table for project file attachments.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE project_attachment (
                project_id UUID NOT NULL,
                media_object_id UUID NOT NULL,
                PRIMARY KEY(project_id, media_object_id)
            )
        SQL);
        // Index/FK names follow Doctrine's auto-generated convention
        // (IDX_<hash> / FK_<hash>) so doctrine:schema:validate matches the
        // entity mapping with no rename diffs.
        $this->addSql('CREATE INDEX IDX_61F9A289166D1F9C ON project_attachment (project_id)');
        $this->addSql('CREATE INDEX IDX_61F9A28964DE5A5 ON project_attachment (media_object_id)');
        $this->addSql('ALTER TABLE project_attachment ADD CONSTRAINT FK_61F9A289166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE project_attachment ADD CONSTRAINT FK_61F9A28964DE5A5 FOREIGN KEY (media_object_id) REFERENCES media_object (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project_attachment DROP CONSTRAINT FK_61F9A289166D1F9C');
        $this->addSql('ALTER TABLE project_attachment DROP CONSTRAINT FK_61F9A28964DE5A5');
        $this->addSql('DROP TABLE project_attachment');
    }
}
