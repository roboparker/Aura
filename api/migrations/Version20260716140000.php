<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Invoice discounts + partial payments (#648): discount columns on invoice
 * (percent-or-fixed, applied between subtotal and tax) and the invoice_payment
 * table (balance due = total − Σ payments; paid only when the balance is 0).
 */
final class Version20260716140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invoice discounts (type/value/amount) + invoice_payment table for partial payments.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invoice_payment (id UUID NOT NULL, amount INT NOT NULL, paid_on DATE NOT NULL, method VARCHAR(40) DEFAULT NULL, note VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, invoice_id UUID NOT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_invoice_payment_invoice ON invoice_payment (invoice_id)');
        $this->addSql('CREATE INDEX IDX_9FF1B2DEB03A8386 ON invoice_payment (created_by_id)');
        $this->addSql('ALTER TABLE invoice_payment ADD CONSTRAINT FK_9FF1B2DE2989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice_payment ADD CONSTRAINT FK_9FF1B2DEB03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice ADD discount_type VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD discount_value INT DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD discount_amount INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_payment DROP CONSTRAINT FK_9FF1B2DE2989F1FD');
        $this->addSql('ALTER TABLE invoice_payment DROP CONSTRAINT FK_9FF1B2DEB03A8386');
        $this->addSql('DROP TABLE invoice_payment');
        $this->addSql('ALTER TABLE invoice DROP discount_type');
        $this->addSql('ALTER TABLE invoice DROP discount_value');
        $this->addSql('ALTER TABLE invoice DROP discount_amount');
    }
}
