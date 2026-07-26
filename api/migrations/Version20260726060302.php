<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Record when an account was created (#763).
 *
 * Nothing has ever timestamped a signup, which made the entire acquisition
 * funnel unmeasurable — you couldn't answer "how many people signed up last
 * week" at all.
 *
 * Backfilled rather than stamped with the migration date. Every user gets a
 * personal "Private" space created in the same flush as the account
 * (PersonalSpaceProvisioner), so `space.created_at` for that space is an
 * accurate signup time for historical rows. Users with no personal space —
 * there shouldn't be any, but a half-failed old signup could leave one — fall
 * back to the earliest timestamp we can attribute to them, and only then to
 * now.
 *
 * Added nullable, backfilled, then made NOT NULL, because adding a NOT NULL
 * column outright fails on a table with rows.
 */
final class Version20260726060302 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.created_at, backfilled from each user\'s personal space (#763)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');

        // Primary source: the personal space, created in the same flush as the user.
        $this->addSql(<<<'SQL'
            UPDATE "user" u
            SET created_at = s.created_at
            FROM space s
            WHERE s.created_by_id = u.id
              AND s.is_personal = true
            SQL);

        // Fallback: the oldest space they created at all.
        $this->addSql(<<<'SQL'
            UPDATE "user" u
            SET created_at = sub.first_seen
            FROM (
                SELECT created_by_id, MIN(created_at) AS first_seen
                FROM space
                WHERE created_by_id IS NOT NULL
                GROUP BY created_by_id
            ) sub
            WHERE sub.created_by_id = u.id
              AND u.created_at IS NULL
            SQL);

        // Last resort, so the NOT NULL below can't fail on a stranded row.
        $this->addSql('UPDATE "user" SET created_at = NOW() WHERE created_at IS NULL');

        $this->addSql('ALTER TABLE "user" ALTER created_at SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP created_at');
    }
}
