<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * In-app notifications for approvals + budget alerts (#667):
 * notification.target_path carries the static deep-link for rows that don't
 * point at an entity (/approvals, /time, /engagements).
 */
final class Version20260716200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'notification.target_path for non-entity deep-links (#667).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD target_path VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP target_path');
    }
}
