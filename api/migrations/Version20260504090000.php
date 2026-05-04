<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the api_token table backing personal access tokens for MCP and
 * other programmatic clients (#92).
 */
final class Version20260504090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add api_token table for MCP / programmatic auth (#92).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE api_token (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                name VARCHAR(80) NOT NULL,
                scopes JSON NOT NULL,
                last_used_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_api_token_hash ON api_token (token_hash)');
        $this->addSql('CREATE INDEX idx_api_token_user ON api_token (user_id)');
        $this->addSql('CREATE INDEX idx_api_token_hash ON api_token (token_hash)');
        $this->addSql(<<<'SQL'
            ALTER TABLE api_token
            ADD CONSTRAINT fk_api_token_user
            FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE api_token');
    }
}
