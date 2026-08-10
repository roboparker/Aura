<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI agents, step 2 (#827): the AI credit ledger.
 *
 * One row per charge against an organization's monthly allowance, with a
 * `pending` → `settled` lifecycle so a reservation can be taken before a model
 * call and reconciled after — the ordering that stops a mid-flight failure
 * overspending. See `App\Entity\AiCreditLedgerEntry` for the shape and
 * `App\Service\AiCreditMeter` for the rules.
 *
 * Nothing to backfill: before this, `ai_credits_per_month` was declared in
 * `PlanCatalog` and read by nothing, so there is no prior usage to carry over.
 *
 * Both indexes are named explicitly here *and* on the entity — an index a
 * migration names but the entity doesn't declare fails
 * `doctrine:schema:validate`.
 */
final class Version20260810160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI agents step 2: create ai_credit_ledger.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ai_credit_ledger (
                id UUID NOT NULL,
                organization_id UUID NOT NULL,
                agent_id UUID DEFAULT NULL,
                period VARCHAR(7) NOT NULL,
                state VARCHAR(16) NOT NULL,
                tokens INT NOT NULL,
                prompt_tokens INT DEFAULT NULL,
                completion_tokens INT DEFAULT NULL,
                provider VARCHAR(32) NOT NULL,
                model VARCHAR(64) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                settled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        // The per-FK indexes Doctrine's schema tool generates for a join
        // column. Their hashed names are what `schema:validate` expects, so
        // they are spelled out here rather than given friendlier ones — a
        // composite index starting with the same column does NOT satisfy the
        // comparator, which is the trap worth knowing about.
        $this->addSql('CREATE INDEX IDX_C07879FB32C8A3DE ON ai_credit_ledger (organization_id)');
        $this->addSql('CREATE INDEX IDX_C07879FB3414710B ON ai_credit_ledger (agent_id)');
        // The balance query's access path: everything for one account in one
        // month. Every reservation takes it, so it is the hot read.
        $this->addSql('CREATE INDEX idx_ai_credit_ledger_org_period ON ai_credit_ledger (organization_id, period)');
        // Backs the housekeeping sweep over lapsed reservations.
        $this->addSql('CREATE INDEX idx_ai_credit_ledger_expiry ON ai_credit_ledger (state, expires_at)');

        // The account's spend dies with the account.
        $this->addSql(<<<'SQL'
            ALTER TABLE ai_credit_ledger
            ADD CONSTRAINT FK_C07879FB32C8A3DE
            FOREIGN KEY (organization_id) REFERENCES organization (id)
            ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        // SET NULL, not CASCADE: removing an agent must not erase what it
        // spent, or a month's bill could be made to vanish by deleting the
        // thing that ran it up.
        $this->addSql(<<<'SQL'
            ALTER TABLE ai_credit_ledger
            ADD CONSTRAINT FK_C07879FB3414710B
            FOREIGN KEY (agent_id) REFERENCES "user" (id)
            ON DELETE SET NULL NOT DEFERRABLE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ai_credit_ledger DROP CONSTRAINT FK_C07879FB32C8A3DE');
        $this->addSql('ALTER TABLE ai_credit_ledger DROP CONSTRAINT FK_C07879FB3414710B');
        $this->addSql('DROP TABLE ai_credit_ledger');
    }
}
