<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Client retainers (#673): the retainer_transaction ledger (deposits +
 * invoice draws; balance = Σ deposits − Σ draws).
 */
final class Version20260717000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'retainer_transaction ledger for client retainers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE retainer_transaction (id UUID NOT NULL, type VARCHAR(10) NOT NULL, amount INT NOT NULL, note VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, client_id UUID NOT NULL, invoice_id UUID DEFAULT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_retainer_txn_client_created ON retainer_transaction (client_id, created_at)');
        $this->addSql('CREATE INDEX IDX_A7A2F9B82989F1FD ON retainer_transaction (invoice_id)');
        $this->addSql('CREATE INDEX IDX_A7A2F9B8B03A8386 ON retainer_transaction (created_by_id)');
        $this->addSql('ALTER TABLE retainer_transaction ADD CONSTRAINT FK_A7A2F9B819EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE retainer_transaction ADD CONSTRAINT FK_A7A2F9B82989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE retainer_transaction ADD CONSTRAINT FK_A7A2F9B8B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE retainer_transaction DROP CONSTRAINT FK_A7A2F9B819EB6921');
        $this->addSql('ALTER TABLE retainer_transaction DROP CONSTRAINT FK_A7A2F9B82989F1FD');
        $this->addSql('ALTER TABLE retainer_transaction DROP CONSTRAINT FK_A7A2F9B8B03A8386');
        $this->addSql('DROP TABLE retainer_transaction');
    }
}
