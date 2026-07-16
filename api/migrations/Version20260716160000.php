<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Engagement budgets + threshold alerts (#651): budget_type (hours|fees),
 * budget_amount (minutes / minor units), and the budget_alerts_sent ledger
 * the nightly sweep checks before emailing space admins.
 */
final class Version20260716160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Engagement budgets: budget_type/budget_amount + budget_alerts_sent ledger.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE engagement ADD budget_type VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE engagement ADD budget_amount INT DEFAULT NULL');
        $this->addSql('ALTER TABLE engagement ADD budget_alerts_sent JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE engagement DROP budget_type');
        $this->addSql('ALTER TABLE engagement DROP budget_amount');
        $this->addSql('ALTER TABLE engagement DROP budget_alerts_sent');
    }
}
