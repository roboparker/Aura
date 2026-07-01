<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add task.recurrence_exceptions (JSON list of Y-m-d dates) — the EXDATE set
 * for a recurring task, populated when a single occurrence is detached from
 * the series on the calendar.
 */
final class Version20260701000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add task.recurrence_exceptions JSON column (recurrence EXDATE set).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task ADD recurrence_exceptions JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP recurrence_exceptions');
    }
}
