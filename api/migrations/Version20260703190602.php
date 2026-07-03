<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260703190602 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invoicing (#445): client, invoice, and invoice_line_item tables.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE client (id UUID NOT NULL, name VARCHAR(200) NOT NULL, email VARCHAR(255) DEFAULT NULL, address TEXT DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, default_rate_amount INT DEFAULT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, space_id UUID NOT NULL, created_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C744045523575340 ON client (space_id)');
        $this->addSql('CREATE INDEX IDX_C7440455B03A8386 ON client (created_by_id)');
        $this->addSql('CREATE INDEX idx_client_space_name ON client (space_id, name)');
        $this->addSql('CREATE TABLE invoice (id UUID NOT NULL, number VARCHAR(40) DEFAULT NULL, status VARCHAR(20) NOT NULL, issue_date DATE DEFAULT NULL, due_date DATE DEFAULT NULL, currency VARCHAR(3) NOT NULL, tax_rate INT NOT NULL, notes TEXT DEFAULT NULL, subtotal INT NOT NULL, tax_amount INT NOT NULL, total INT NOT NULL, public_token VARCHAR(64) DEFAULT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, space_id UUID NOT NULL, client_id UUID NOT NULL, created_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_90651744AE981E3B ON invoice (public_token)');
        $this->addSql('CREATE INDEX IDX_9065174423575340 ON invoice (space_id)');
        $this->addSql('CREATE INDEX IDX_90651744B03A8386 ON invoice (created_by_id)');
        $this->addSql('CREATE INDEX idx_invoice_space_created ON invoice (space_id, created_at)');
        $this->addSql('CREATE INDEX idx_invoice_client ON invoice (client_id)');
        $this->addSql('CREATE TABLE invoice_line_item (id UUID NOT NULL, description VARCHAR(500) NOT NULL, quantity DOUBLE PRECISION NOT NULL, unit_amount INT NOT NULL, amount INT NOT NULL, position INT NOT NULL, invoice_id UUID NOT NULL, source_time_entry_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_F1F9275B2989F1FD ON invoice_line_item (invoice_id)');
        $this->addSql('CREATE INDEX IDX_F1F9275BDBDFDBEC ON invoice_line_item (source_time_entry_id)');
        $this->addSql('CREATE INDEX idx_invoice_line_item_invoice_position ON invoice_line_item (invoice_id, position)');
        $this->addSql('ALTER TABLE client ADD CONSTRAINT FK_C744045523575340 FOREIGN KEY (space_id) REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE client ADD CONSTRAINT FK_C7440455B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_9065174423575340 FOREIGN KEY (space_id) REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_9065174419EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_90651744B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice_line_item ADD CONSTRAINT FK_F1F9275B2989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice_line_item ADD CONSTRAINT FK_F1F9275BDBDFDBEC FOREIGN KEY (source_time_entry_id) REFERENCES time_entry (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE client DROP CONSTRAINT FK_C744045523575340');
        $this->addSql('ALTER TABLE client DROP CONSTRAINT FK_C7440455B03A8386');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT FK_9065174423575340');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT FK_9065174419EB6921');
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT FK_90651744B03A8386');
        $this->addSql('ALTER TABLE invoice_line_item DROP CONSTRAINT FK_F1F9275B2989F1FD');
        $this->addSql('ALTER TABLE invoice_line_item DROP CONSTRAINT FK_F1F9275BDBDFDBEC');
        $this->addSql('DROP TABLE client');
        $this->addSql('DROP TABLE invoice');
        $this->addSql('DROP TABLE invoice_line_item');
    }
}
