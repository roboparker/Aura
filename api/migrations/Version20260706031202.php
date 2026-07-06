<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706031202 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add engagement.description (markdown) + engagement_attachment join table (contract files).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE engagement_attachment (engagement_id UUID NOT NULL, media_object_id UUID NOT NULL, PRIMARY KEY (engagement_id, media_object_id))');
        $this->addSql('CREATE INDEX IDX_B432A70ED30F6F97 ON engagement_attachment (engagement_id)');
        $this->addSql('CREATE INDEX IDX_B432A70E64DE5A5 ON engagement_attachment (media_object_id)');
        $this->addSql('ALTER TABLE engagement_attachment ADD CONSTRAINT FK_B432A70ED30F6F97 FOREIGN KEY (engagement_id) REFERENCES engagement (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE engagement_attachment ADD CONSTRAINT FK_B432A70E64DE5A5 FOREIGN KEY (media_object_id) REFERENCES media_object (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE engagement ADD description TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE engagement_attachment DROP CONSTRAINT FK_B432A70ED30F6F97');
        $this->addSql('ALTER TABLE engagement_attachment DROP CONSTRAINT FK_B432A70E64DE5A5');
        $this->addSql('DROP TABLE engagement_attachment');
        $this->addSql('ALTER TABLE engagement DROP description');
    }
}
