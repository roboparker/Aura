<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Configurable space dashboards (#759): one row per widget placed on a
 * space's dashboard.
 *
 * Additive only — a new table nobody reads yet, so this is safe to deploy
 * ahead of the UI and safe to roll back.
 */
final class Version20260726021943 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add dashboard_widget for configurable space dashboards (#759)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dashboard_widget (id UUID NOT NULL, type VARCHAR(64) NOT NULL, config JSON NOT NULL, position INT NOT NULL, width INT NOT NULL, height INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, space_id UUID NOT NULL, created_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6AC217EB23575340 ON dashboard_widget (space_id)');
        $this->addSql('CREATE INDEX IDX_6AC217EBB03A8386 ON dashboard_widget (created_by_id)');
        $this->addSql('CREATE INDEX idx_dashboard_widget_space_position ON dashboard_widget (space_id, position)');
        $this->addSql('ALTER TABLE dashboard_widget ADD CONSTRAINT FK_6AC217EB23575340 FOREIGN KEY (space_id) REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE dashboard_widget ADD CONSTRAINT FK_6AC217EBB03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dashboard_widget DROP CONSTRAINT FK_6AC217EB23575340');
        $this->addSql('ALTER TABLE dashboard_widget DROP CONSTRAINT FK_6AC217EBB03A8386');
        $this->addSql('DROP TABLE dashboard_widget');
    }
}
