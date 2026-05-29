<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add `space.updated_at` (Gedmo Timestampable on update).
 *
 * Powers the "edited 2h ago" / "updated yesterday" metadata line on
 * the spaces grid. Backfilled to `created_at` for every existing row
 * so the column can land NOT NULL.
 */
final class Version20260528010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add space.updated_at column maintained by Gedmo Timestampable.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE space ADD COLUMN updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE space SET updated_at = created_at WHERE updated_at IS NULL');
        $this->addSql('ALTER TABLE space ALTER COLUMN updated_at SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE space DROP COLUMN updated_at');
    }
}
