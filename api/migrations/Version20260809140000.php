<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 2, step 2: move every subscription onto an organization.
 *
 * After step 1 each user has a personal organization and each space belongs to
 * one, so the two other ways a subscription could be owned are now redundant:
 *
 *  - `owner_user_id` → the user's personal organization. A personal org *is*
 *    the user's account, so pointing a row at the user was a second way of
 *    saying the same thing, and every billing surface had to remember which
 *    form applied.
 *  - `space_id` (legacy, pre-account-model) → the organization that owns that
 *    space.
 *
 * The columns stay in place, unused, until step 3 drops them — so this is
 * reversible, and a row written by an old deploy mid-rollout still resolves.
 *
 * Skips rows that already have an organization, and rows whose source can't be
 * resolved: an unresolvable row is left exactly as it was rather than being
 * guessed at, because attaching a plan to the wrong account is worse than
 * leaving it where a human can see it.
 */
final class Version20260809140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2 step 2: move subscriptions from owner_user/space onto organizations.';
    }

    public function up(Schema $schema): void
    {
        // Personal subscriptions → the owner's personal organization.
        $this->addSql(<<<'SQL'
            UPDATE subscription s
            SET organization_id = o.id
            FROM organization o
            WHERE s.organization_id IS NULL
              AND s.owner_user_id IS NOT NULL
              AND o.is_personal = TRUE
              AND o.created_by_id = s.owner_user_id
            SQL);

        // Legacy per-space subscriptions → the organization owning that space.
        $this->addSql(<<<'SQL'
            UPDATE subscription s
            SET organization_id = sp.organization_id
            FROM space sp
            WHERE s.organization_id IS NULL
              AND s.space_id IS NOT NULL
              AND sp.id = s.space_id
              AND sp.organization_id IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Only rows this migration attached are detached. That's sound because
        // the three owner columns were mutually exclusive before it ran: a row
        // carrying both an organization *and* an owner_user/space can only have
        // got the organization from here. The source columns were never
        // cleared, so they still say where each row came from.
        $this->addSql(<<<'SQL'
            UPDATE subscription
            SET organization_id = NULL
            WHERE owner_user_id IS NOT NULL OR space_id IS NOT NULL
            SQL);
    }
}
