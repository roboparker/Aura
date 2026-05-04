<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds digested_at to notification so DispatchNotificationDigestCommand
 * (see #102) can mark rows as already-shipped in a digest email and skip
 * them on subsequent runs. Independent of read_at, which tracks user
 * acknowledgment of the in-app row.
 */
final class Version20260504030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add digested_at to notification for digest delivery tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD digested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP digested_at');
    }
}
