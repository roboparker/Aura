<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds a nullable due_date column to the task table so users can set a
 * date a task is due. Distinct from created_on (immutable audit field)
 * and completed_on (set when checked off).
 */
final class Version20260502120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add task.due_date for user-set task due dates.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task ADD due_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP due_date');
    }
}
