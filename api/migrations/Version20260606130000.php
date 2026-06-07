<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * User segments for A/B testing, beta gating, and gradual rollouts.
 *
 * `segment` holds the cohort config (key, rollout %, role rules, enabled
 * flag); `segment_membership` holds per-user manual include/exclude
 * overrides. Membership is evaluated at request time (see SegmentEvaluator)
 * and surfaced as a synthetic `ROLE_SEGMENT_<KEY>` role, so there's nothing
 * to backfill onto existing users.
 */
final class Version20260606130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add segment and segment_membership tables for user segmentation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE segment (
                id UUID NOT NULL,
                created_by_id UUID DEFAULT NULL,
                segment_key VARCHAR(64) NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT DEFAULT NULL,
                purpose VARCHAR(20) DEFAULT 'beta' NOT NULL,
                enabled BOOLEAN DEFAULT TRUE NOT NULL,
                rollout_percentage SMALLINT DEFAULT NULL,
                included_roles JSON NOT NULL,
                created_on TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_segment_key ON segment (segment_key)');
        $this->addSql('CREATE INDEX idx_segment_created_by ON segment (created_by_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE segment
                ADD CONSTRAINT fk_segment_created_by FOREIGN KEY (created_by_id)
                REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE segment_membership (
                id UUID NOT NULL,
                segment_id UUID NOT NULL,
                user_id UUID NOT NULL,
                mode VARCHAR(16) DEFAULT 'include' NOT NULL,
                joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_segment_membership_user ON segment_membership (segment_id, user_id)');
        $this->addSql('CREATE INDEX idx_segment_membership_user ON segment_membership (user_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE segment_membership
                ADD CONSTRAINT fk_segment_membership_segment FOREIGN KEY (segment_id)
                REFERENCES segment (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE segment_membership
                ADD CONSTRAINT fk_segment_membership_user FOREIGN KEY (user_id)
                REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE segment_membership DROP CONSTRAINT fk_segment_membership_user');
        $this->addSql('ALTER TABLE segment_membership DROP CONSTRAINT fk_segment_membership_segment');
        $this->addSql('ALTER TABLE segment DROP CONSTRAINT fk_segment_created_by');
        $this->addSql('DROP TABLE segment_membership');
        $this->addSql('DROP TABLE segment');
    }
}
