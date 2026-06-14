<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widen notification.reminder_offset from 16 to 80 chars. The expanded
 * reminder model (#227) stores a per-fire key here — absolute reminders
 * encode an ISO-8601 timestamp and "repeat daily" reminders append the fire
 * date, both of which overflow the old 16-char column.
 */
final class Version20260613140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen notification.reminder_offset to 80 chars for the expanded reminder keys.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ALTER COLUMN reminder_offset TYPE VARCHAR(80)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ALTER COLUMN reminder_offset TYPE VARCHAR(16)');
    }
}
