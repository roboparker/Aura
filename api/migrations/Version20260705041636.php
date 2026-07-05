<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260705041636 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add billing_project + billing_category tables (Harvest-style time/invoicing).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE billing_category (id UUID NOT NULL, name VARCHAR(120) NOT NULL, rate_amount INT NOT NULL, position INT NOT NULL, billing_project_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_billing_category_project ON billing_category (billing_project_id)');
        $this->addSql('CREATE TABLE billing_project (id UUID NOT NULL, name VARCHAR(200) NOT NULL, currency VARCHAR(3) DEFAULT NULL, archived BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, space_id UUID NOT NULL, client_id UUID NOT NULL, created_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_449D5B3923575340 ON billing_project (space_id)');
        $this->addSql('CREATE INDEX IDX_449D5B39B03A8386 ON billing_project (created_by_id)');
        $this->addSql('CREATE INDEX idx_billing_project_space_name ON billing_project (space_id, name)');
        $this->addSql('CREATE INDEX idx_billing_project_client ON billing_project (client_id)');
        $this->addSql('ALTER TABLE billing_category ADD CONSTRAINT FK_1E90703D9A0A700D FOREIGN KEY (billing_project_id) REFERENCES billing_project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE billing_project ADD CONSTRAINT FK_449D5B3923575340 FOREIGN KEY (space_id) REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE billing_project ADD CONSTRAINT FK_449D5B3919EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE billing_project ADD CONSTRAINT FK_449D5B39B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_category DROP CONSTRAINT FK_1E90703D9A0A700D');
        $this->addSql('ALTER TABLE billing_project DROP CONSTRAINT FK_449D5B3923575340');
        $this->addSql('ALTER TABLE billing_project DROP CONSTRAINT FK_449D5B3919EB6921');
        $this->addSql('ALTER TABLE billing_project DROP CONSTRAINT FK_449D5B39B03A8386');
        $this->addSql('DROP TABLE billing_category');
        $this->addSql('DROP TABLE billing_project');
    }
}
