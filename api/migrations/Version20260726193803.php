<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Board automations (#764): rules and their run log.
 *
 * Additive only — two new tables nothing reads yet, so this is safe to deploy
 * ahead of the UI and safe to roll back.
 */
final class Version20260726193803 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add automation + automation_run for board rules (#764)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE automation (id UUID NOT NULL, name VARCHAR(120) NOT NULL, enabled BOOLEAN NOT NULL, trigger_event VARCHAR(40) NOT NULL, graph JSON NOT NULL, last_error TEXT DEFAULT NULL, last_run_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, board_id UUID NOT NULL, space_id UUID NOT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C9739CEEE7EC5785 ON automation (board_id)');
        $this->addSql('CREATE INDEX IDX_C9739CEE23575340 ON automation (space_id)');
        $this->addSql('CREATE INDEX IDX_C9739CEEB03A8386 ON automation (created_by_id)');
        $this->addSql('CREATE INDEX idx_automation_board_event ON automation (board_id, trigger_event, enabled)');
        $this->addSql('CREATE TABLE automation_run (id UUID NOT NULL, task_title VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, depth INT NOT NULL, steps JSON NOT NULL, ran_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, automation_id UUID NOT NULL, task_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_391E9A84D1C5DDC3 ON automation_run (automation_id)');
        $this->addSql('CREATE INDEX idx_automation_run_rule_time ON automation_run (automation_id, ran_at)');
        $this->addSql('CREATE INDEX idx_automation_run_task ON automation_run (task_id)');
        $this->addSql('ALTER TABLE automation ADD CONSTRAINT FK_C9739CEEE7EC5785 FOREIGN KEY (board_id) REFERENCES board (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE automation ADD CONSTRAINT FK_C9739CEE23575340 FOREIGN KEY (space_id) REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE automation ADD CONSTRAINT FK_C9739CEEB03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE automation_run ADD CONSTRAINT FK_391E9A84D1C5DDC3 FOREIGN KEY (automation_id) REFERENCES automation (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE automation_run ADD CONSTRAINT FK_391E9A848DB60186 FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE automation DROP CONSTRAINT FK_C9739CEEE7EC5785');
        $this->addSql('ALTER TABLE automation DROP CONSTRAINT FK_C9739CEE23575340');
        $this->addSql('ALTER TABLE automation DROP CONSTRAINT FK_C9739CEEB03A8386');
        $this->addSql('ALTER TABLE automation_run DROP CONSTRAINT FK_391E9A84D1C5DDC3');
        $this->addSql('ALTER TABLE automation_run DROP CONSTRAINT FK_391E9A848DB60186');
        $this->addSql('DROP TABLE automation');
        $this->addSql('DROP TABLE automation_run');
    }
}
