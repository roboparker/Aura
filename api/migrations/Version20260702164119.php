<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702164119 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace user.sso_subject/sso_provider with the sso_identity table (multi-provider social login).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE sso_identity (id UUID NOT NULL, provider VARCHAR(32) NOT NULL, subject VARCHAR(255) NOT NULL, email VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_sso_identity_user ON sso_identity (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_sso_identity_provider_subject ON sso_identity (provider, subject)');
        $this->addSql('ALTER TABLE sso_identity ADD CONSTRAINT FK_CD71466EA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('DROP INDEX uniq_8d93d6497847f59e');
        $this->addSql('ALTER TABLE "user" DROP sso_subject');
        $this->addSql('ALTER TABLE "user" DROP sso_provider');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sso_identity DROP CONSTRAINT FK_CD71466EA76ED395');
        $this->addSql('DROP TABLE sso_identity');
        $this->addSql('ALTER TABLE "user" ADD sso_subject VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD sso_provider VARCHAR(50) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_8d93d6497847f59e ON "user" (sso_subject)');
    }
}
