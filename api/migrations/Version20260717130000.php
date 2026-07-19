<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Harvest naming alignment (pre-release, no BC): Engagement → Project,
 * EngagementCategory → Service, category rate → billing rate. Pure identifier
 * renames — no data movement.
 */
final class Version20260717130000 extends AbstractMigration
{
    /** @var array<string, string> old table => new table (rename first) */
    private const TABLES = [
        'engagement' => 'project',
        'engagement_category' => 'service',
        'engagement_user_rate' => 'project_user_rate',
        'engagement_attachment' => 'project_attachment',
    ];

    /** @var array<string, array{0: string, 1: string}> new-table => [old col, new col] */
    private const COLUMNS = [
        'board' => ['engagement_id', 'project_id'],
        'expense' => ['engagement_id', 'project_id'],
        'time_entry' => ['engagement_id', 'project_id'],
        'service' => ['engagement_id', 'project_id'],
        'project_user_rate' => ['engagement_id', 'project_id'],
        'project_attachment' => ['engagement_id', 'project_id'],
    ];

    /** @var array<string, string> old index => new index */
    private const INDEXES = [
        'idx_engagement_category_engagement' => 'idx_service_project',
        'idx_engagement_client' => 'idx_project_client',
        'idx_engagement_space_name' => 'idx_project_space_name',
        'idx_engagement_user_rate_user' => 'idx_project_user_rate_user',
        'idx_expense_engagement' => 'idx_expense_project',
        'idx_time_entry_engagement' => 'idx_time_entry_project',
        'uniq_engagement_user_rate' => 'uniq_project_user_rate',
        // Doctrine auto-named FK-column indexes whose hash shifts with the
        // table/column renames above.
        'idx_58562b47d30f6f97' => 'IDX_58562B47166D1F9C',
        'idx_d86f014123575340' => 'IDX_2FB3D0EE23575340',
        'idx_d86f0141b03a8386' => 'IDX_2FB3D0EEB03A8386',
        'idx_b432a70ed30f6f97' => 'IDX_61F9A289166D1F9C',
        'idx_b432a70e64de5a5' => 'IDX_61F9A28964DE5A5',
    ];

    public function getDescription(): string
    {
        return 'Rename Engagement→Project, EngagementCategory→Service, rate_amount→billing_rate.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $old => $new) {
            $this->addSql(sprintf('ALTER TABLE %s RENAME TO %s', $old, $new));
        }
        foreach (self::COLUMNS as $table => [$old, $new]) {
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', $table, $old, $new));
        }
        $this->addSql('ALTER TABLE service RENAME COLUMN rate_amount TO billing_rate');
        foreach (self::INDEXES as $old => $new) {
            $this->addSql(sprintf('ALTER INDEX %s RENAME TO %s', $old, $new));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::INDEXES as $old => $new) {
            $this->addSql(sprintf('ALTER INDEX %s RENAME TO %s', $new, $old));
        }
        $this->addSql('ALTER TABLE service RENAME COLUMN billing_rate TO rate_amount');
        foreach (self::COLUMNS as $table => [$old, $new]) {
            $this->addSql(sprintf('ALTER TABLE %s RENAME COLUMN %s TO %s', $table, $new, $old));
        }
        foreach (array_reverse(self::TABLES, true) as $old => $new) {
            $this->addSql(sprintf('ALTER TABLE %s RENAME TO %s', $new, $old));
        }
    }
}
