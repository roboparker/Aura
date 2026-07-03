<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add user.sso_subject (unique) + user.sso_provider — the OIDC SSO identity
 * link for sign-in (#267).
 */
final class Version20260702072655 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.sso_subject/sso_provider for OIDC SSO sign-in.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD sso_subject VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD sso_provider VARCHAR(50) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6497847F59E ON "user" (sso_subject)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D6497847F59E');
        $this->addSql('ALTER TABLE "user" DROP sso_subject');
        $this->addSql('ALTER TABLE "user" DROP sso_provider');
    }
}
