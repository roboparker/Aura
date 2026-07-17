<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-person + cost rates (#653): engagement_user_rate (per-engagement
 * billable rate overrides), space_membership.cost_rate_amount (space-level
 * internal cost), and time_entry.cost_rate_amount (the per-entry snapshot
 * profitability reports read).
 */
final class Version20260716180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'engagement_user_rate table + cost_rate_amount on space_membership and time_entry.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE engagement_user_rate (id UUID NOT NULL, rate_amount INT NOT NULL, engagement_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_engagement_user_rate ON engagement_user_rate (engagement_id, user_id)');
        $this->addSql('CREATE INDEX idx_engagement_user_rate_user ON engagement_user_rate (user_id)');
        $this->addSql('ALTER TABLE engagement_user_rate ADD CONSTRAINT FK_1F155505D30F6F97 FOREIGN KEY (engagement_id) REFERENCES engagement (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE engagement_user_rate ADD CONSTRAINT FK_1F155505A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE space_membership ADD cost_rate_amount INT DEFAULT NULL');
        $this->addSql('ALTER TABLE time_entry ADD cost_rate_amount INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE engagement_user_rate DROP CONSTRAINT FK_1F155505D30F6F97');
        $this->addSql('ALTER TABLE engagement_user_rate DROP CONSTRAINT FK_1F155505A76ED395');
        $this->addSql('DROP TABLE engagement_user_rate');
        $this->addSql('ALTER TABLE space_membership DROP cost_rate_amount');
        $this->addSql('ALTER TABLE time_entry DROP cost_rate_amount');
    }
}
