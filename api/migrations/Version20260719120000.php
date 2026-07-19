<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Recurring-invoice end conditions (#598): add nullable `recurrence_end_date`
 * ("until" date) and `recurrence_count` ("after N invoices") to `invoice`. Both
 * null = recurs forever; at most one may be set. Purely additive.
 */
final class Version20260719120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add recurrence_end_date + recurrence_count end conditions to invoice (#598).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD recurrence_end_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD recurrence_count INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP recurrence_end_date');
        $this->addSql('ALTER TABLE invoice DROP recurrence_count');
    }
}
