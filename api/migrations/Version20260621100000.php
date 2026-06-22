<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cancellation feedback: the "why are you leaving?" churn survey captured at
 * account deactivation / deletion and subscription cancellation.
 *
 * Both FKs are SET NULL so the row outlives the action that created it — most
 * importantly account deletion, which removes the user and anonymizes the
 * feedback rather than cascading it away. See App\Entity\CancellationFeedback.
 */
final class Version20260621100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cancellation_feedback table for the churn survey.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE cancellation_feedback (
                id UUID NOT NULL,
                user_id UUID DEFAULT NULL,
                space_id UUID DEFAULT NULL,
                context VARCHAR(32) NOT NULL,
                reason VARCHAR(32) NOT NULL,
                comment TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_cancellation_feedback_user ON cancellation_feedback (user_id)');
        $this->addSql('CREATE INDEX idx_cancellation_feedback_space ON cancellation_feedback (space_id)');
        $this->addSql('CREATE INDEX idx_cancellation_feedback_context ON cancellation_feedback (context)');
        $this->addSql(<<<'SQL'
            ALTER TABLE cancellation_feedback
                ADD CONSTRAINT fk_cancellation_feedback_user FOREIGN KEY (user_id)
                REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE cancellation_feedback
                ADD CONSTRAINT fk_cancellation_feedback_space FOREIGN KEY (space_id)
                REFERENCES space (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cancellation_feedback DROP CONSTRAINT fk_cancellation_feedback_user');
        $this->addSql('ALTER TABLE cancellation_feedback DROP CONSTRAINT fk_cancellation_feedback_space');
        $this->addSql('DROP TABLE cancellation_feedback');
    }
}
