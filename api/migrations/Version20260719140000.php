<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stripe Connect for client invoice payments (#connect): a per-space Express
 * connected account so invoice payments settle to the space owner rather than
 * the platform. Additive nullable/defaulted columns on `space`.
 */
final class Version20260719140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Stripe Connect account fields to space (#connect).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE space ADD stripe_connect_account_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE space ADD stripe_connect_charges_enabled BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE space ADD stripe_connect_details_submitted BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE space DROP stripe_connect_account_id');
        $this->addSql('ALTER TABLE space DROP stripe_connect_charges_enabled');
        $this->addSql('ALTER TABLE space DROP stripe_connect_details_submitted');
    }
}
