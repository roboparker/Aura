<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-user usage tracking for cost estimation / pricing (issue: usage stats).
 *
 * Two tables:
 *  - `user_usage_counter`: live, append-light counters bucketed per
 *    (user, UTC day, metric). API + MCP calls are incremented here off the
 *    request latency path (terminate listener / MCP tool chokepoint) via a
 *    single narrow UPSERT, so we never write one row per call.
 *  - `user_usage_snapshot`: the nightly rollup queried for pricing — one row
 *    per (user, day) carrying that day's call counts plus a point-in-time
 *    disk-bytes sum (media_object) and a logical db-bytes estimate.
 *
 * See App\Service\UsageRecorder and App\Service\UsageSnapshotBuilder.
 */
final class Version20260611120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_usage_counter and user_usage_snapshot tables for per-user usage/cost tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_usage_counter (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                day DATE NOT NULL,
                metric VARCHAR(16) NOT NULL,
                count BIGINT DEFAULT 0 NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_user_usage_counter ON user_usage_counter (user_id, day, metric)');
        $this->addSql('CREATE INDEX idx_user_usage_counter_day ON user_usage_counter (day)');
        $this->addSql(<<<'SQL'
            ALTER TABLE user_usage_counter
                ADD CONSTRAINT fk_user_usage_counter_user FOREIGN KEY (user_id)
                REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE user_usage_snapshot (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                captured_on DATE NOT NULL,
                disk_bytes BIGINT DEFAULT 0 NOT NULL,
                db_bytes_est BIGINT DEFAULT 0 NOT NULL,
                api_calls BIGINT DEFAULT 0 NOT NULL,
                mcp_calls BIGINT DEFAULT 0 NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_user_usage_snapshot ON user_usage_snapshot (user_id, captured_on)');
        $this->addSql('CREATE INDEX idx_user_usage_snapshot_captured_on ON user_usage_snapshot (captured_on)');
        $this->addSql(<<<'SQL'
            ALTER TABLE user_usage_snapshot
                ADD CONSTRAINT fk_user_usage_snapshot_user FOREIGN KEY (user_id)
                REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_usage_snapshot DROP CONSTRAINT fk_user_usage_snapshot_user');
        $this->addSql('DROP TABLE user_usage_snapshot');
        $this->addSql('ALTER TABLE user_usage_counter DROP CONSTRAINT fk_user_usage_counter_user');
        $this->addSql('DROP TABLE user_usage_counter');
    }
}
