<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Managed expense categories (#671): the expense_category table (per-space
 * curated list with an optional unit price) + expense.expense_category_id.
 */
final class Version20260716233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'expense_category table + expense.expense_category_id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE expense_category (id UUID NOT NULL, name VARCHAR(80) NOT NULL, unit_amount INT DEFAULT NULL, archived BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, space_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_expense_category_space_name ON expense_category (space_id, name)');
        $this->addSql('ALTER TABLE expense_category ADD CONSTRAINT FK_C02DDB3823575340 FOREIGN KEY (space_id) REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE expense ADD expense_category_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_2D3A8DA66B2A3179 ON expense (expense_category_id)');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT FK_2D3A8DA66B2A3179 FOREIGN KEY (expense_category_id) REFERENCES expense_category (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expense DROP CONSTRAINT FK_2D3A8DA66B2A3179');
        $this->addSql('ALTER TABLE expense DROP expense_category_id');
        $this->addSql('ALTER TABLE expense_category DROP CONSTRAINT FK_C02DDB3823575340');
        $this->addSql('DROP TABLE expense_category');
    }
}
