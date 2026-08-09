<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 2, step 4: everyone in a space belongs to the account that owns it.
 *
 * Space membership has implied organization membership since Phase 1c — the
 * auto-join in `SpaceMemberAdder` puts every *new* member in the owning
 * organization. Members who joined a space before that shipped never got a row,
 * so `Organization::hasMember()` says no for them while they demonstrably have
 * access to its content. Anything reasoning about the account rather than the
 * space (seat counts, `entitlingPlansForUser`, the org member list) sees an
 * organization smaller than it really is.
 *
 * The role mirrors `SpaceMemberAdder::ORG_ROLE_FOR_SPACE_ROLE`, which is the
 * rule new members already get:
 *
 *   space admin  → org member (a billable seat)
 *   space member → org guest  (free, restricted)
 *
 * **Never downgrades.** An existing organization membership is left exactly as
 * it is — someone who is already an Owner or Admin must not be demoted to guest
 * because they also happen to sit in a space as a plain member. The insert is
 * guarded by NOT EXISTS rather than an upsert for that reason.
 *
 * Seat counts can rise for organizations that had un-joined space admins. That
 * is the correct number rather than a new charge: those people already had
 * access, they were simply invisible to the count. Stripe quantity is pushed by
 * the next membership change; there is no seat sync here because a migration
 * has no business making paid API calls.
 */
final class Version20260809200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2 step 4: backfill organization_membership from space_membership.';
    }

    public function up(Schema $schema): void
    {
        // DISTINCT ON keeps one row per (organization, user): somebody who
        // admins one space in an org and is a plain member of another should
        // get the *stronger* role, so order admin ahead of member.
        $this->addSql(<<<'SQL'
            INSERT INTO organization_membership (id, organization_id, user_id, role, joined_at)
            SELECT DISTINCT ON (s.organization_id, sm.user_id)
                gen_random_uuid(),
                s.organization_id,
                sm.user_id,
                CASE WHEN sm.role = 'admin' THEN 'member' ELSE 'guest' END,
                NOW()
            FROM space_membership sm
            JOIN space s ON s.id = sm.space_id
            WHERE NOT EXISTS (
                SELECT 1 FROM organization_membership om
                WHERE om.organization_id = s.organization_id
                  AND om.user_id = sm.user_id
            )
            ORDER BY s.organization_id, sm.user_id, (sm.role = 'admin') DESC
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Nothing to undo safely. The rows this created are indistinguishable
        // from ones the auto-join would have written, so deleting "the
        // backfilled ones" would also strip legitimate memberships. Leaving
        // them is harmless: they describe access the users already have.
    }
}
