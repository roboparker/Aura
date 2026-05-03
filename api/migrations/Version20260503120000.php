<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds a nullable recurrence_rule column to the task table. Stores the
 * recurrence pattern as a JSON object (frequency + interval); see
 * App\Entity\Task::$recurrenceRule.
 */
final class Version20260503120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add task.recurrence_rule for recurring tasks.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task ADD recurrence_rule JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP recurrence_rule');
    }
}
