<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Timesheet approvals (#654): the timesheet_submission table — one row per
 * (space, user, week), pending/approved freeze the week's time entries.
 */
final class Version20260716190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'timesheet_submission table (weekly submissions with approve/reject).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE timesheet_submission (id UUID NOT NULL, week_start DATE NOT NULL, status VARCHAR(20) NOT NULL, submitted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, note VARCHAR(500) DEFAULT NULL, space_id UUID NOT NULL, user_id UUID NOT NULL, decided_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_timesheet_submission_week ON timesheet_submission (space_id, user_id, week_start)');
        $this->addSql('CREATE INDEX idx_timesheet_submission_space_status ON timesheet_submission (space_id, status)');
        $this->addSql('CREATE INDEX idx_timesheet_submission_user ON timesheet_submission (user_id)');
        $this->addSql('CREATE INDEX IDX_3C20375EE26B496B ON timesheet_submission (decided_by_id)');
        $this->addSql('ALTER TABLE timesheet_submission ADD CONSTRAINT FK_3C20375E23575340 FOREIGN KEY (space_id) REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE timesheet_submission ADD CONSTRAINT FK_3C20375EA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE timesheet_submission ADD CONSTRAINT FK_3C20375EE26B496B FOREIGN KEY (decided_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE timesheet_submission DROP CONSTRAINT FK_3C20375E23575340');
        $this->addSql('ALTER TABLE timesheet_submission DROP CONSTRAINT FK_3C20375EA76ED395');
        $this->addSql('ALTER TABLE timesheet_submission DROP CONSTRAINT FK_3C20375EE26B496B');
        $this->addSql('DROP TABLE timesheet_submission');
    }
}
