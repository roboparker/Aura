<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Phase 2, step 3: make the account model mandatory. **One-way.**
 *
 * Steps 1 and 2 put every space inside an organization and moved every
 * subscription onto one, leaving `subscription.space_id` and `owner_user_id`
 * populated but unread. This turns the invariant into a constraint and removes
 * the columns, which is what stops the old shapes creeping back.
 *
 * `up()` **verifies before it constrains**. A space with no organization would
 * otherwise fail on the ALTER with a Postgres error naming a column rather than
 * a cause; failing early with a count is the difference between a five-minute
 * fix and a confusing rollback. Anything unmigrated at this point is a bug in
 * step 1 or 2, not something to paper over here.
 *
 * `down()` is deliberately unimplemented. Re-adding the columns is trivial;
 * repopulating them is not — the mapping from an organization back to "which
 * user or space did this originally pay for" is exactly the information being
 * discarded. Rolling back past this point means restoring from backup, which
 * the pre-deploy hook takes.
 */
final class Version20260809170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 2 step 3: space.organization_id NOT NULL; drop subscription.space_id + owner_user_id.';
    }

    public function up(Schema $schema): void
    {
        $this->guardAgainstUnmigratedRows();

        $this->addSql('ALTER TABLE space ALTER COLUMN organization_id SET NOT NULL');

        $this->addSql('ALTER TABLE subscription DROP CONSTRAINT IF EXISTS fk_subscription_space');
        $this->addSql('ALTER TABLE subscription DROP CONSTRAINT IF EXISTS fk_subscription_owner_user');
        $this->addSql('DROP INDEX IF EXISTS idx_subscription_space');
        $this->addSql('DROP INDEX IF EXISTS idx_subscription_owner_user');
        $this->addSql('ALTER TABLE subscription DROP COLUMN space_id');
        $this->addSql('ALTER TABLE subscription DROP COLUMN owner_user_id');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration(
            'Phase 2 step 3 discards which user or space a subscription originally paid for. '
            . 'Restore from the pre-deploy backup instead.',
        );
    }

    /**
     * Refuse to run if step 1 or 2 left anything behind. Both counts are zero
     * on a correctly migrated database.
     */
    private function guardAgainstUnmigratedRows(): void
    {
        $orphanSpaces = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM space WHERE organization_id IS NULL',
        );
        $this->abortIf(
            $orphanSpaces > 0,
            sprintf(
                '%d space(s) still have no organization. Re-run the Phase 2 step 1 backfill '
                . '(Version20260809090000) before applying this migration.',
                $orphanSpaces,
            ),
        );

        $orphanSubscriptions = (int) $this->connection->fetchOne(
            'SELECT count(*) FROM subscription WHERE organization_id IS NULL'
            . ' AND (space_id IS NOT NULL OR owner_user_id IS NOT NULL)',
        );
        $this->abortIf(
            $orphanSubscriptions > 0,
            sprintf(
                '%d subscription(s) still point at a space or user with no organization resolved. '
                . 'Re-run the Phase 2 step 2 backfill (Version20260809140000) before applying this migration.',
                $orphanSubscriptions,
            ),
        );
    }
}
