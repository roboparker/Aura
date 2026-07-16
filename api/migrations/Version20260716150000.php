<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Expenses (#650): the expense table (engagement-scoped costs with a billed
 * lock + optional receipt MediaObject) and invoice_line_item.source_expense_id
 * so invoice lines back-link the expense they bill (mirroring
 * source_time_entry_id, used by void to release the expense).
 */
final class Version20260716150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expense table + invoice_line_item.source_expense_id back-link.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE expense (id UUID NOT NULL, spent_on DATE NOT NULL, category VARCHAR(80) DEFAULT NULL, amount INT NOT NULL, currency VARCHAR(3) DEFAULT NULL, description TEXT DEFAULT NULL, billable BOOLEAN NOT NULL, billed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, space_id UUID NOT NULL, engagement_id UUID DEFAULT NULL, user_id UUID NOT NULL, receipt_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_expense_space_spent ON expense (space_id, spent_on)');
        $this->addSql('CREATE INDEX idx_expense_engagement ON expense (engagement_id)');
        $this->addSql('CREATE INDEX idx_expense_user ON expense (user_id)');
        $this->addSql('CREATE INDEX IDX_2D3A8DA62B5CA896 ON expense (receipt_id)');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT FK_2D3A8DA623575340 FOREIGN KEY (space_id) REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT FK_2D3A8DA6D30F6F97 FOREIGN KEY (engagement_id) REFERENCES engagement (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT FK_2D3A8DA6A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT FK_2D3A8DA62B5CA896 FOREIGN KEY (receipt_id) REFERENCES media_object (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice_line_item ADD source_expense_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_F1F9275B8F21AA5F ON invoice_line_item (source_expense_id)');
        $this->addSql('ALTER TABLE invoice_line_item ADD CONSTRAINT FK_F1F9275B8F21AA5F FOREIGN KEY (source_expense_id) REFERENCES expense (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_line_item DROP CONSTRAINT FK_F1F9275B8F21AA5F');
        $this->addSql('ALTER TABLE invoice_line_item DROP source_expense_id');
        $this->addSql('ALTER TABLE expense DROP CONSTRAINT FK_2D3A8DA623575340');
        $this->addSql('ALTER TABLE expense DROP CONSTRAINT FK_2D3A8DA6D30F6F97');
        $this->addSql('ALTER TABLE expense DROP CONSTRAINT FK_2D3A8DA6A76ED395');
        $this->addSql('ALTER TABLE expense DROP CONSTRAINT FK_2D3A8DA62B5CA896');
        $this->addSql('DROP TABLE expense');
    }
}
