<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702234406 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calendar sync (#582 Phase D): last-pushed timestamp for pull conflict guard.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_event_link ADD last_pushed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_event_link DROP last_pushed_at');
    }
}
