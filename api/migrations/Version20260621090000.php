<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Billing: one row per paid "Team" subscription attached to a Space.
 *
 * The row mirrors Stripe state — created/updated only by the billing webhook
 * handler, never through the API. An entitling subscription (active /
 * trialing / past_due) lifts the free space-member cap and the free MCP
 * throughput cap for the space's members. See App\Entity\Subscription.
 */
final class Version20260621090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add subscription table for paid Team subscriptions attached to a space.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE subscription (
                id UUID NOT NULL,
                space_id UUID NOT NULL,
                created_by_id UUID DEFAULT NULL,
                plan VARCHAR(32) DEFAULT 'team' NOT NULL,
                status VARCHAR(32) NOT NULL,
                billing_interval VARCHAR(16) DEFAULT NULL,
                seats INT DEFAULT 1 NOT NULL,
                stripe_customer_id VARCHAR(255) DEFAULT NULL,
                stripe_subscription_id VARCHAR(255) DEFAULT NULL,
                stripe_price_id VARCHAR(255) DEFAULT NULL,
                current_period_end TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                cancel_at_period_end BOOLEAN DEFAULT false NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_subscription_space ON subscription (space_id)');
        $this->addSql('CREATE INDEX idx_subscription_created_by ON subscription (created_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_subscription_stripe_id ON subscription (stripe_subscription_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE subscription
                ADD CONSTRAINT fk_subscription_space FOREIGN KEY (space_id)
                REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE subscription
                ADD CONSTRAINT fk_subscription_created_by FOREIGN KEY (created_by_id)
                REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription DROP CONSTRAINT fk_subscription_space');
        $this->addSql('ALTER TABLE subscription DROP CONSTRAINT fk_subscription_created_by');
        $this->addSql('DROP TABLE subscription');
    }
}
