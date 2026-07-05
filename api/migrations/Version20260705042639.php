<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260705042639 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rework time_entry for the Harvest model: drop project/task, add billing_project + category (required at the API layer).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_entry DROP CONSTRAINT fk_6e537c0c8db60186');
        $this->addSql('ALTER TABLE time_entry DROP CONSTRAINT fk_6e537c0c166d1f9c');
        $this->addSql('DROP INDEX idx_6e537c0c8db60186');
        $this->addSql('DROP INDEX idx_time_entry_project');
        $this->addSql('ALTER TABLE time_entry ADD billing_project_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE time_entry ADD category_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE time_entry DROP project_id');
        $this->addSql('ALTER TABLE time_entry DROP task_id');
        $this->addSql('ALTER TABLE time_entry ADD CONSTRAINT FK_6E537C0C9A0A700D FOREIGN KEY (billing_project_id) REFERENCES billing_project (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE time_entry ADD CONSTRAINT FK_6E537C0C12469DE2 FOREIGN KEY (category_id) REFERENCES billing_category (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_time_entry_billing_project ON time_entry (billing_project_id)');
        $this->addSql('CREATE INDEX idx_time_entry_category ON time_entry (category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE time_entry DROP CONSTRAINT FK_6E537C0C9A0A700D');
        $this->addSql('ALTER TABLE time_entry DROP CONSTRAINT FK_6E537C0C12469DE2');
        $this->addSql('DROP INDEX idx_time_entry_billing_project');
        $this->addSql('DROP INDEX idx_time_entry_category');
        $this->addSql('ALTER TABLE time_entry ADD project_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE time_entry ADD task_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE time_entry DROP billing_project_id');
        $this->addSql('ALTER TABLE time_entry DROP category_id');
        $this->addSql('ALTER TABLE time_entry ADD CONSTRAINT fk_6e537c0c8db60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE time_entry ADD CONSTRAINT fk_6e537c0c166d1f9c FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_6e537c0c8db60186 ON time_entry (task_id)');
        $this->addSql('CREATE INDEX idx_time_entry_project ON time_entry (project_id)');
    }
}
