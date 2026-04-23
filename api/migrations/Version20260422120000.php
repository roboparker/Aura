<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema with UUIDv7 identifiers.
 *
 * Squashes the prior integer-PK migrations into a single fresh baseline. All
 * primary and foreign keys use PostgreSQL's native `uuid` type; application
 * code issues UUIDv7 values via `doctrine.uuid_generator` (configured via
 * `framework.uid.default_uuid_version: 7`), which keeps index locality
 * roughly monotonic despite random-looking identifiers.
 */
final class Version20260422120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial UUIDv7 schema (user, task, tag, task_tag, password_reset_token, greeting).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE "user" (id UUID NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');

        $this->addSql('CREATE TABLE task (id UUID NOT NULL, owner_id UUID NOT NULL, title VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, created_on TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, completed_on TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, position INT DEFAULT 0 NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_task_owner ON task (owner_id)');
        $this->addSql('CREATE INDEX idx_task_owner_position ON task (owner_id, position)');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB257E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE tag (id UUID NOT NULL, owner_id UUID NOT NULL, title VARCHAR(100) NOT NULL, description TEXT DEFAULT NULL, color VARCHAR(7) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_tag_owner ON tag (owner_id)');
        $this->addSql('ALTER TABLE tag ADD CONSTRAINT FK_389B7837E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE task_tag (task_id UUID NOT NULL, tag_id UUID NOT NULL, PRIMARY KEY (task_id, tag_id))');
        $this->addSql('CREATE INDEX IDX_6C0B4F048DB60186 ON task_tag (task_id)');
        $this->addSql('CREATE INDEX IDX_6C0B4F04BAD26311 ON task_tag (tag_id)');
        $this->addSql('ALTER TABLE task_tag ADD CONSTRAINT FK_6C0B4F048DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_tag ADD CONSTRAINT FK_6C0B4F04BAD26311 FOREIGN KEY (tag_id) REFERENCES tag (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE password_reset_token (id UUID NOT NULL, user_id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6B7BA4B6B3BC57DA ON password_reset_token (token_hash)');
        $this->addSql('CREATE INDEX IDX_6B7BA4B6A76ED395 ON password_reset_token (user_id)');
        $this->addSql('CREATE INDEX idx_token_hash ON password_reset_token (token_hash)');
        $this->addSql('ALTER TABLE password_reset_token ADD CONSTRAINT FK_6B7BA4B6A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE greeting (id UUID NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE password_reset_token DROP CONSTRAINT FK_6B7BA4B6A76ED395');
        $this->addSql('ALTER TABLE task_tag DROP CONSTRAINT FK_6C0B4F04BAD26311');
        $this->addSql('ALTER TABLE task_tag DROP CONSTRAINT FK_6C0B4F048DB60186');
        $this->addSql('ALTER TABLE tag DROP CONSTRAINT FK_389B7837E3C61F9');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB257E3C61F9');
        $this->addSql('DROP TABLE greeting');
        $this->addSql('DROP TABLE password_reset_token');
        $this->addSql('DROP TABLE task_tag');
        $this->addSql('DROP TABLE tag');
        $this->addSql('DROP TABLE task');
        $this->addSql('DROP TABLE "user"');
    }
}
