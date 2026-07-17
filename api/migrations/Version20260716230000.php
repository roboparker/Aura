<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-line-item tax rates (#670): invoice_line_item.tax_rate (basis points,
 * null = the invoice-level rate, 0 = explicitly tax-free).
 */
final class Version20260716230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'invoice_line_item.tax_rate per-line override.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_line_item ADD tax_rate INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_line_item DROP tax_rate');
    }
}
