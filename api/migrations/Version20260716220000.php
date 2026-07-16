<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Client portal (#674): client.portal_token — sha256 of the portal link
 * token (unique; rotating invalidates the previous link).
 */
final class Version20260716220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'client.portal_token for the tokenized client portal.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD portal_token VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C7440455F7DA88A9 ON client (portal_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_C7440455F7DA88A9');
        $this->addSql('ALTER TABLE client DROP portal_token');
    }
}
