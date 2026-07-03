<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702232527 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calendar sync (#582 Phase B): incremental pull cursor on oauth_connection.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE oauth_connection ADD calendar_sync_token TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE oauth_connection ADD calendar_synced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE oauth_connection DROP calendar_sync_token');
        $this->addSql('ALTER TABLE oauth_connection DROP calendar_synced_at');
    }
}
