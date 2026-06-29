<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Space-scoped API keys (#space-roles): add api_token.space_id (a non-null
 * space marks a scoped key) + the api_token_role join carrying the key's roles.
 * Personal tokens (space_id NULL) are unchanged.
 */
final class Version20260629024225 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add api_token.space_id + api_token_role for space-scoped API keys.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_token_role (api_token_id UUID NOT NULL, space_role_id UUID NOT NULL, PRIMARY KEY (api_token_id, space_role_id))');
        $this->addSql('CREATE INDEX IDX_F2C7C31992E52D36 ON api_token_role (api_token_id)');
        $this->addSql('CREATE INDEX IDX_F2C7C3195617F9E4 ON api_token_role (space_role_id)');
        $this->addSql('ALTER TABLE api_token_role ADD CONSTRAINT FK_F2C7C31992E52D36 FOREIGN KEY (api_token_id) REFERENCES api_token (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE api_token_role ADD CONSTRAINT FK_F2C7C3195617F9E4 FOREIGN KEY (space_role_id) REFERENCES space_role (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE api_token ADD space_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE api_token ADD CONSTRAINT FK_7BA2F5EB23575340 FOREIGN KEY (space_id) REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_7BA2F5EB23575340 ON api_token (space_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE api_token_role DROP CONSTRAINT FK_F2C7C31992E52D36');
        $this->addSql('ALTER TABLE api_token_role DROP CONSTRAINT FK_F2C7C3195617F9E4');
        $this->addSql('DROP TABLE api_token_role');
        $this->addSql('ALTER TABLE api_token DROP CONSTRAINT FK_7BA2F5EB23575340');
        $this->addSql('DROP INDEX IDX_7BA2F5EB23575340');
        $this->addSql('ALTER TABLE api_token DROP space_id');
    }
}
