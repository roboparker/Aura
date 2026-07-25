<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260725030744 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Timeline (#timeline): board timeline_enabled toggle (replaces the start-field FKs) '
            . '+ global field system_key + seed the canonical "Start date" system field.';
    }

    public function up(Schema $schema): void
    {
        // Replace the #749 per-board start-field FKs with a simple enabled flag:
        // the start field is now the one canonical global field seeded below.
        $this->addSql('ALTER TABLE board DROP CONSTRAINT fk_58562b47cdf9f00d');
        $this->addSql('ALTER TABLE board DROP CONSTRAINT fk_58562b47e28e998a');
        $this->addSql('DROP INDEX idx_58562b47cdf9f00d');
        $this->addSql('DROP INDEX idx_58562b47e28e998a');
        $this->addSql('ALTER TABLE board ADD timeline_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE board DROP timeline_start_field_id');
        $this->addSql('ALTER TABLE board DROP timeline_start_global_field_id');
        $this->addSql('ALTER TABLE global_custom_field_definition ADD system_key VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_7491FA1347280172 ON global_custom_field_definition (system_key)');

        // Seed the canonical "Start date" system field the Timeline feature
        // reads. systemKey makes it undeletable and resolvable by a fixed
        // handle. Guarded so a re-run (or a fixture that already added it)
        // doesn't collide on the unique systemKey / name.
        $this->addSql(<<<'SQL'
            INSERT INTO global_custom_field_definition
                (id, name, kind, subtype, config, footer, nullable, position, visibility, created_at, system_key)
            SELECT gen_random_uuid(), 'Start date', 'date', 'date', '{}', NULL, true, 0, 'both', NOW(), 'timeline_start'
            WHERE NOT EXISTS (
                SELECT 1 FROM global_custom_field_definition WHERE system_key = 'timeline_start'
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE board ADD timeline_start_field_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE board ADD timeline_start_global_field_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE board DROP timeline_enabled');
        $this->addSql('ALTER TABLE board ADD CONSTRAINT fk_58562b47cdf9f00d FOREIGN KEY (timeline_start_global_field_id) REFERENCES global_custom_field_definition (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE board ADD CONSTRAINT fk_58562b47e28e998a FOREIGN KEY (timeline_start_field_id) REFERENCES custom_field_definition (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_58562b47cdf9f00d ON board (timeline_start_global_field_id)');
        $this->addSql('CREATE INDEX idx_58562b47e28e998a ON board (timeline_start_field_id)');
        $this->addSql('DROP INDEX UNIQ_7491FA1347280172');
        $this->addSql('ALTER TABLE global_custom_field_definition DROP system_key');
    }
}
