<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename BillingProject/BillingCategory → Engagement/EngagementCategory
 * (the Stripe subscription "billing" is unrelated and untouched).
 * Data-preserving: RENAME the tables + FK columns + named indexes rather than
 * drop/create.
 */
final class Version20260705181009 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename billing_project/billing_category tables → engagement/engagement_category.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE billing_project RENAME TO engagement');
        $this->addSql('ALTER TABLE billing_category RENAME TO engagement_category');
        $this->addSql('ALTER TABLE engagement_category RENAME COLUMN billing_project_id TO engagement_id');
        $this->addSql('ALTER TABLE board RENAME COLUMN billing_project_id TO engagement_id');
        $this->addSql('ALTER TABLE time_entry RENAME COLUMN billing_project_id TO engagement_id');
        $this->addSql('ALTER INDEX idx_billing_category_project RENAME TO idx_engagement_category_project');
        $this->addSql('ALTER INDEX idx_billing_project_space_name RENAME TO idx_engagement_space_name');
        $this->addSql('ALTER INDEX idx_billing_project_client RENAME TO idx_engagement_client');
        $this->addSql('ALTER INDEX idx_time_entry_billing_project RENAME TO idx_time_entry_engagement');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_time_entry_engagement RENAME TO idx_time_entry_billing_project');
        $this->addSql('ALTER INDEX idx_engagement_client RENAME TO idx_billing_project_client');
        $this->addSql('ALTER INDEX idx_engagement_space_name RENAME TO idx_billing_project_space_name');
        $this->addSql('ALTER INDEX idx_engagement_category_project RENAME TO idx_billing_category_project');
        $this->addSql('ALTER TABLE time_entry RENAME COLUMN engagement_id TO billing_project_id');
        $this->addSql('ALTER TABLE board RENAME COLUMN engagement_id TO billing_project_id');
        $this->addSql('ALTER TABLE engagement_category RENAME COLUMN engagement_id TO billing_project_id');
        $this->addSql('ALTER TABLE engagement_category RENAME TO billing_category');
        $this->addSql('ALTER TABLE engagement RENAME TO billing_project');
    }
}
