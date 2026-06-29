<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widen project_field_visibility.visibility to hold a comma-joined surface SET
 * (list/board/calendar) — adds the Calendar surface for custom fields. Legacy
 * single values ('both'/'list'/'board') still parse, so no data conversion.
 */
final class Version20260629043354 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen project_field_visibility.visibility to VARCHAR(32) for the calendar surface.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project_field_visibility ALTER visibility TYPE VARCHAR(32)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project_field_visibility ALTER visibility TYPE VARCHAR(16)');
    }
}
