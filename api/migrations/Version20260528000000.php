<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add `space.visibility` (`private` | `shared`).
 *
 * Orthogonal to membership role: private spaces are creator-only and
 * reject invites / add-member calls; shared spaces accept both. Every
 * personal space is backfilled to `private` to match the new invariant
 * (`Space::validatePersonalIsPrivate()`); all other existing spaces
 * default to `shared` to preserve current behavior.
 */
final class Version20260528000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add space.visibility column (private/shared) with personal spaces backfilled to private.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE space ADD COLUMN visibility VARCHAR(16) NOT NULL DEFAULT 'shared'");
        $this->addSql("UPDATE space SET visibility = 'private' WHERE is_personal = true");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE space DROP COLUMN visibility');
    }
}
