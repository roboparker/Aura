<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702213208 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add oauth_connection table for OAuth account connections (#581).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE oauth_connection (id UUID NOT NULL, provider VARCHAR(32) NOT NULL, external_id VARCHAR(255) DEFAULT NULL, email VARCHAR(255) DEFAULT NULL, access_token TEXT NOT NULL, refresh_token TEXT DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, scopes JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_oauth_connection_user ON oauth_connection (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_oauth_connection_user_provider ON oauth_connection (user_id, provider)');
        $this->addSql('ALTER TABLE oauth_connection ADD CONSTRAINT FK_BB31DCDDA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE oauth_connection DROP CONSTRAINT FK_BB31DCDDA76ED395');
        $this->addSql('DROP TABLE oauth_connection');
    }
}
