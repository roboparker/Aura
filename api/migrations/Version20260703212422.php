<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260703212422 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Recurring invoices (#445): recurrence_frequency, recurrence_interval, next_issue_date on invoice.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invoice ADD recurrence_frequency VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD recurrence_interval INT DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD next_issue_date DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invoice DROP recurrence_frequency');
        $this->addSql('ALTER TABLE invoice DROP recurrence_interval');
        $this->addSql('ALTER TABLE invoice DROP next_issue_date');
    }
}
