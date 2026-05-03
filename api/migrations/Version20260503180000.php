<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds a nullable `preferences` JSON column on the user table for
 * theme + notification settings (see App\Entity\User::DEFAULT_PREFERENCES).
 * Existing rows stay null and the entity getter merges defaults in.
 */
final class Version20260503180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.preferences for theme and notification settings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD preferences JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP preferences');
    }
}
