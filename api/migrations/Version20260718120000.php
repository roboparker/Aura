<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename timesheet_submission → timesheet_approval (Harvest naming alignment;
 * pre-release, no BC). Data-preserving ALTER … RENAME — the table, its named
 * indexes/constraint, and the Doctrine FK-hash index + FK constraints (whose
 * hash prefix shifts with the table name: 3C20375E → 843D34AE) are all
 * renamed in place so no rows are lost and schema:validate stays in sync.
 */
final class Version20260718120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename timesheet_submission table to timesheet_approval (+ its indexes/constraints).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE timesheet_submission RENAME TO timesheet_approval');
        $this->addSql('ALTER INDEX uniq_timesheet_submission_week RENAME TO uniq_timesheet_approval_week');
        $this->addSql('ALTER INDEX idx_timesheet_submission_space_status RENAME TO idx_timesheet_approval_space_status');
        $this->addSql('ALTER INDEX idx_timesheet_submission_user RENAME TO idx_timesheet_approval_user');
        $this->addSql('ALTER INDEX idx_3c20375ee26b496b RENAME TO idx_843d34aee26b496b');
        $this->addSql('ALTER TABLE timesheet_approval RENAME CONSTRAINT fk_3c20375e23575340 TO fk_843d34ae23575340');
        $this->addSql('ALTER TABLE timesheet_approval RENAME CONSTRAINT fk_3c20375ea76ed395 TO fk_843d34aea76ed395');
        $this->addSql('ALTER TABLE timesheet_approval RENAME CONSTRAINT fk_3c20375ee26b496b TO fk_843d34aee26b496b');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE timesheet_approval RENAME CONSTRAINT fk_843d34ae23575340 TO fk_3c20375e23575340');
        $this->addSql('ALTER TABLE timesheet_approval RENAME CONSTRAINT fk_843d34aea76ed395 TO fk_3c20375ea76ed395');
        $this->addSql('ALTER TABLE timesheet_approval RENAME CONSTRAINT fk_843d34aee26b496b TO fk_3c20375ee26b496b');
        $this->addSql('ALTER INDEX idx_843d34aee26b496b RENAME TO idx_3c20375ee26b496b');
        $this->addSql('ALTER INDEX idx_timesheet_approval_user RENAME TO idx_timesheet_submission_user');
        $this->addSql('ALTER INDEX idx_timesheet_approval_space_status RENAME TO idx_timesheet_submission_space_status');
        $this->addSql('ALTER INDEX uniq_timesheet_approval_week RENAME TO uniq_timesheet_submission_week');
        $this->addSql('ALTER TABLE timesheet_approval RENAME TO timesheet_submission');
    }
}
