<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260703100852 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Time tracking (#444): time_entry table + one-running-timer-per-user partial unique index.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE time_entry (id UUID NOT NULL, description TEXT DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, duration_seconds INT DEFAULT NULL, billable BOOLEAN NOT NULL, rate_amount INT DEFAULT NULL, rate_currency VARCHAR(3) DEFAULT NULL, billed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, space_id UUID NOT NULL, project_id UUID DEFAULT NULL, task_id UUID DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6E537C0C23575340 ON time_entry (space_id)');
        $this->addSql('CREATE INDEX IDX_6E537C0C8DB60186 ON time_entry (task_id)');
        $this->addSql('CREATE INDEX IDX_6E537C0CA76ED395 ON time_entry (user_id)');
        $this->addSql('CREATE INDEX idx_time_entry_space_started ON time_entry (space_id, started_at)');
        $this->addSql('CREATE INDEX idx_time_entry_user_started ON time_entry (user_id, started_at)');
        $this->addSql('CREATE INDEX idx_time_entry_project ON time_entry (project_id)');
        $this->addSql('ALTER TABLE time_entry ADD CONSTRAINT FK_6E537C0C23575340 FOREIGN KEY (space_id) REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE time_entry ADD CONSTRAINT FK_6E537C0C166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE time_entry ADD CONSTRAINT FK_6E537C0C8DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE time_entry ADD CONSTRAINT FK_6E537C0CA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        // At most one running timer (ended_at IS NULL) per user.
        $this->addSql('CREATE UNIQUE INDEX uniq_time_entry_running_per_user ON time_entry (user_id) WHERE ended_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE time_entry DROP CONSTRAINT FK_6E537C0C23575340');
        $this->addSql('ALTER TABLE time_entry DROP CONSTRAINT FK_6E537C0C166D1F9C');
        $this->addSql('ALTER TABLE time_entry DROP CONSTRAINT FK_6E537C0C8DB60186');
        $this->addSql('ALTER TABLE time_entry DROP CONSTRAINT FK_6E537C0CA76ED395');
        $this->addSql('DROP TABLE time_entry');
    }
}
