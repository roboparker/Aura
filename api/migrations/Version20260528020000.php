<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add `space.color` — nullable hex from the WCAG-AA avatar palette.
 *
 * When null the UI falls back to the creator's `personalizedColor`,
 * so every existing space picks up its owner's swatch automatically
 * without a data backfill.
 */
final class Version20260528020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add space.color column for the space-tile override (nullable, palette-validated).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE space ADD COLUMN color VARCHAR(7) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE space DROP COLUMN color');
    }
}
