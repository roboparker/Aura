<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 2, step 1: give every user a personal organization and put every
 * account-less space inside one.
 *
 * **Behaviour-neutral by design.** `space.organization_id` stays nullable and
 * `PlanGate` still resolves through its existing fallback chain, so nothing
 * reads differently the moment this runs. The point is to get the backfill
 * verified in production *before* anything depends on it — the NOT NULL
 * constraint and the code deletions come in a later step, and those are the
 * one-way ones.
 *
 * Every space must land in the right account: a space attached to the wrong
 * organization is either invisible to its members or visible to strangers.
 * The rule is deliberately the narrowest one that can't be wrong — a space with
 * no organization belongs to whoever created it, which is exactly what
 * `SpaceCreateProcessor` meant when it left the column null.
 *
 * Idempotent: re-running creates no duplicate organizations (guarded by the
 * partial unique index and a NOT EXISTS) and moves no space twice.
 */
final class Version20260809090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2 step 1: personal organizations + backfill space.organization_id (behaviour-neutral).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE organization ADD is_personal BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_organization_personal_per_user'
            . ' ON organization (created_by_id) WHERE (is_personal = true)',
        );

        // One personal organization per user who doesn't already have one.
        //
        // The slug has to be unique and stable. `o-<email local part>` is the
        // readable choice, but two users can share a local part across domains,
        // so collisions fall back to a suffix from the user id — deterministic,
        // and it can't collide again on a re-run.
        $this->addSql(<<<'SQL'
            INSERT INTO organization (id, name, slug, is_personal, created_by_id, created_at, updated_at)
            SELECT
                gen_random_uuid(),
                COALESCE(NULLIF(TRIM(u.given_name || ' ' || u.family_name), ''), SPLIT_PART(u.email, '@', 1)),
                'o-' || candidate.slug_base ||
                    CASE WHEN EXISTS (
                        SELECT 1 FROM organization o2 WHERE o2.slug = 'o-' || candidate.slug_base
                    ) THEN '-' || SUBSTRING(REPLACE(u.id::text, '-', '') FROM 1 FOR 8) ELSE '' END,
                TRUE,
                u.id,
                NOW(),
                NOW()
            FROM "user" u
            CROSS JOIN LATERAL (
                SELECT COALESCE(
                    NULLIF(TRIM(BOTH '-' FROM REGEXP_REPLACE(LOWER(SPLIT_PART(u.email, '@', 1)), '[^a-z0-9]+', '-', 'g')), ''),
                    'user'
                ) AS slug_base
            ) candidate
            WHERE NOT EXISTS (
                SELECT 1 FROM organization o
                WHERE o.created_by_id = u.id AND o.is_personal = TRUE
            )
            SQL);

        // The creator is the owner of their own account, so every "does this org
        // have an owner?" invariant holds for personal ones too.
        $this->addSql(<<<'SQL'
            INSERT INTO organization_membership (id, organization_id, user_id, role, joined_at)
            SELECT gen_random_uuid(), o.id, o.created_by_id, 'owner', NOW()
            FROM organization o
            WHERE o.is_personal = TRUE
              AND NOT EXISTS (
                  SELECT 1 FROM organization_membership m
                  WHERE m.organization_id = o.id AND m.user_id = o.created_by_id
              )
            SQL);

        // Every account-less space joins its creator's personal organization.
        $this->addSql(<<<'SQL'
            UPDATE space s
            SET organization_id = o.id
            FROM organization o
            WHERE s.organization_id IS NULL
              AND o.is_personal = TRUE
              AND o.created_by_id = s.created_by_id
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Detach only the spaces this migration attached — a space that was
        // already in an organization must keep it.
        $this->addSql(<<<'SQL'
            UPDATE space s
            SET organization_id = NULL
            FROM organization o
            WHERE s.organization_id = o.id AND o.is_personal = TRUE
            SQL);
        $this->addSql('DELETE FROM organization_membership WHERE organization_id IN'
            . ' (SELECT id FROM organization WHERE is_personal = TRUE)');
        $this->addSql('DELETE FROM organization WHERE is_personal = TRUE');
        $this->addSql('DROP INDEX uniq_organization_personal_per_user');
        $this->addSql('ALTER TABLE organization DROP is_personal');
    }
}
