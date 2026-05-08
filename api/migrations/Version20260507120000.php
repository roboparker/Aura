<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill `space_membership` from the legacy `project_member` join
 * table, then drop the table (#185). After PR 1 (#181) every project
 * lives in a space, but only the project owner was added as the
 * space's admin — other project members weren't touched.
 *
 * Without this backfill, switching the access predicates over to
 * space membership in PR 2 would silently revoke access for everyone
 * except project owners. The INSERT … ON CONFLICT DO NOTHING is
 * idempotent against the (space_id, user_id) unique index, so a member
 * who's already in the target space (e.g. the owner-as-admin row from
 * PR 1's backfill) is left alone.
 *
 * Once the rows are mirrored over, the join table itself goes away.
 */
final class Version20260507120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill space_membership from project_member, then drop project_member (#185).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO space_membership (id, space_id, user_id, role, joined_at)
            SELECT gen_random_uuid(), p.space_id, pm.user_id, 'member', NOW()
            FROM project_member pm
            JOIN project p ON p.id = pm.project_id
            ON CONFLICT (space_id, user_id) DO NOTHING
        SQL);

        $this->addSql('DROP TABLE project_member');
    }

    public function down(Schema $schema): void
    {
        // Recreate the join table — empty. Re-deriving the membership
        // rows from space_membership would over-grant (every space
        // member becomes a project member), so the down path leaves
        // the table empty and expects an operator to backfill from
        // application data if rolling back.
        $this->addSql(<<<'SQL'
            CREATE TABLE project_member (
                project_id UUID NOT NULL,
                user_id UUID NOT NULL,
                PRIMARY KEY(project_id, user_id)
            )
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE project_member
            ADD CONSTRAINT fk_project_member_project
            FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE project_member
            ADD CONSTRAINT fk_project_member_user
            FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE
        SQL);
    }
}
