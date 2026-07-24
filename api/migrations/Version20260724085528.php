<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260724085528 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Board Timeline (#timeline): nullable start-field FKs (local + global custom field).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board ADD timeline_start_field_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE board ADD timeline_start_global_field_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE board ADD CONSTRAINT FK_58562B47E28E998A FOREIGN KEY (timeline_start_field_id) REFERENCES custom_field_definition (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE board ADD CONSTRAINT FK_58562B47CDF9F00D FOREIGN KEY (timeline_start_global_field_id) REFERENCES global_custom_field_definition (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_58562B47E28E998A ON board (timeline_start_field_id)');
        $this->addSql('CREATE INDEX IDX_58562B47CDF9F00D ON board (timeline_start_global_field_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE board DROP CONSTRAINT FK_58562B47E28E998A');
        $this->addSql('ALTER TABLE board DROP CONSTRAINT FK_58562B47CDF9F00D');
        $this->addSql('DROP INDEX IDX_58562B47E28E998A');
        $this->addSql('DROP INDEX IDX_58562B47CDF9F00D');
        $this->addSql('ALTER TABLE board DROP timeline_start_field_id');
        $this->addSql('ALTER TABLE board DROP timeline_start_global_field_id');
    }
}
