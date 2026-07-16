<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Estimates (#652): the estimate + estimate_line_item tables — Invoice's
 * pre-sales sibling with a draft/sent/accepted/declined lifecycle, a public
 * accept/decline token, and a converted_invoice back-link.
 */
final class Version20260716170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Estimate + estimate_line_item tables (quotes with accept/decline + convert-to-invoice).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE estimate (id UUID NOT NULL, number VARCHAR(40) DEFAULT NULL, status VARCHAR(20) NOT NULL, currency VARCHAR(3) NOT NULL, tax_rate INT NOT NULL, notes TEXT DEFAULT NULL, subtotal INT NOT NULL, tax_amount INT NOT NULL, total INT NOT NULL, public_token VARCHAR(64) DEFAULT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, space_id UUID NOT NULL, client_id UUID NOT NULL, converted_invoice_id UUID DEFAULT NULL, created_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D2EA4607AE981E3B ON estimate (public_token)');
        $this->addSql('CREATE INDEX idx_estimate_space_created ON estimate (space_id, created_at)');
        $this->addSql('CREATE INDEX idx_estimate_client ON estimate (client_id)');
        $this->addSql('CREATE INDEX IDX_D2EA46079DC100AB ON estimate (converted_invoice_id)');
        $this->addSql('CREATE INDEX IDX_D2EA4607B03A8386 ON estimate (created_by_id)');
        $this->addSql('ALTER TABLE estimate ADD CONSTRAINT FK_D2EA460723575340 FOREIGN KEY (space_id) REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE estimate ADD CONSTRAINT FK_D2EA460719EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE estimate ADD CONSTRAINT FK_D2EA46079DC100AB FOREIGN KEY (converted_invoice_id) REFERENCES invoice (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE estimate ADD CONSTRAINT FK_D2EA4607B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE TABLE estimate_line_item (id UUID NOT NULL, description VARCHAR(500) NOT NULL, quantity DOUBLE PRECISION NOT NULL, unit_amount INT NOT NULL, amount INT NOT NULL, position INT NOT NULL, estimate_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B8D23DEB85F23082 ON estimate_line_item (estimate_id)');
        $this->addSql('CREATE INDEX idx_estimate_line_item_estimate_position ON estimate_line_item (estimate_id, position)');
        $this->addSql('ALTER TABLE estimate_line_item ADD CONSTRAINT FK_B8D23DEB85F23082 FOREIGN KEY (estimate_id) REFERENCES estimate (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE estimate_line_item DROP CONSTRAINT FK_B8D23DEB85F23082');
        $this->addSql('DROP TABLE estimate_line_item');
        $this->addSql('ALTER TABLE estimate DROP CONSTRAINT FK_D2EA460723575340');
        $this->addSql('ALTER TABLE estimate DROP CONSTRAINT FK_D2EA460719EB6921');
        $this->addSql('ALTER TABLE estimate DROP CONSTRAINT FK_D2EA46079DC100AB');
        $this->addSql('ALTER TABLE estimate DROP CONSTRAINT FK_D2EA4607B03A8386');
        $this->addSql('DROP TABLE estimate');
    }
}
