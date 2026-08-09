<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Audit table for site-admin deletions of other people's data.
 *
 * Every column except `actor_id` is a **snapshot**: the row's whole purpose is
 * to survive its target being destroyed, so referencing the target with an FK
 * would defeat it. `actor_id` is SET NULL so the trail outlives the admin's own
 * account too.
 */
final class Version20260808180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin_action_log for site-admin deletions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE admin_action_log (
                id UUID NOT NULL,
                actor_id UUID DEFAULT NULL,
                actor_email VARCHAR(180) NOT NULL,
                action VARCHAR(32) NOT NULL,
                target_type VARCHAR(32) NOT NULL,
                target_id UUID NOT NULL,
                target_label VARCHAR(255) NOT NULL,
                target_owner_email VARCHAR(180) DEFAULT NULL,
                reason TEXT NOT NULL,
                owner_notified BOOLEAN NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_ADMIN_ACTION_LOG_ACTOR ON admin_action_log (actor_id)');
        $this->addSql('CREATE INDEX idx_admin_action_log_created ON admin_action_log (created_at)');
        $this->addSql('CREATE INDEX idx_admin_action_log_target ON admin_action_log (target_type, target_id)');
        $this->addSql(
            'ALTER TABLE admin_action_log ADD CONSTRAINT FK_ADMIN_ACTION_LOG_ACTOR'
            . ' FOREIGN KEY (actor_id) REFERENCES "user" (id) ON DELETE SET NULL'
            . ' NOT DEFERRABLE INITIALLY IMMEDIATE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_action_log');
    }
}
