<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Invoice branding (#669): space-level logo, default terms/footer, invoice
 * number prefix + next-number counter.
 */
final class Version20260716210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Space invoice branding: logo FK, terms, number prefix + next counter.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE space ADD invoice_logo_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE space ADD invoice_terms TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE space ADD invoice_number_prefix VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE space ADD invoice_number_next INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_2972C13A0B562299 ON space (invoice_logo_id)');
        $this->addSql('ALTER TABLE space ADD CONSTRAINT FK_2972C13A0B562299 FOREIGN KEY (invoice_logo_id) REFERENCES media_object (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE space DROP CONSTRAINT FK_2972C13A0B562299');
        $this->addSql('ALTER TABLE space DROP invoice_logo_id');
        $this->addSql('ALTER TABLE space DROP invoice_terms');
        $this->addSql('ALTER TABLE space DROP invoice_number_prefix');
        $this->addSql('ALTER TABLE space DROP invoice_number_next');
    }
}
