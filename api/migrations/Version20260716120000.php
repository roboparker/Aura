<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Late-payment reminders (#649): invoice.reminders_enabled (per-invoice
 * opt-out, on by default) + invoice.reminders_sent_at (JSON log of reminders
 * already emailed — the sweep's idempotency ledger).
 */
final class Version20260716120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invoice late-payment reminders: reminders_enabled toggle + reminders_sent_at log.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD reminders_enabled BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE invoice ADD reminders_sent_at JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP reminders_enabled');
        $this->addSql('ALTER TABLE invoice DROP reminders_sent_at');
    }
}
