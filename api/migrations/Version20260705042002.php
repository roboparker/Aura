<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260705042002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project.billing_project_id FK (assign task projects to a billing project).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD billing_project_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE9A0A700D FOREIGN KEY (billing_project_id) REFERENCES billing_project (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_2FB3D0EE9A0A700D ON project (billing_project_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP CONSTRAINT FK_2FB3D0EE9A0A700D');
        $this->addSql('DROP INDEX IDX_2FB3D0EE9A0A700D');
        $this->addSql('ALTER TABLE project DROP billing_project_id');
    }
}
