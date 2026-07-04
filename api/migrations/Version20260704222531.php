<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704222531 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Subscription owned by an account (#billing Phase 1b): add organization + owner_user, backfill org from the space.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription ADD organization_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE subscription ADD owner_user_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE subscription ALTER space_id DROP NOT NULL');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D332C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D32B18554A FOREIGN KEY (owner_user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_subscription_organization ON subscription (organization_id)');
        $this->addSql('CREATE INDEX idx_subscription_owner_user ON subscription (owner_user_id)');

        // Backfill: point every existing (shared-space) subscription at the
        // Organization that Phase 1a wrapped its space in. Resolution then goes
        // account-first, so plans keep resolving to the same value.
        $this->addSql(<<<'SQL'
            UPDATE subscription s SET organization_id = sp.organization_id
            FROM space sp
            WHERE s.space_id = sp.id AND sp.organization_id IS NOT NULL AND s.organization_id IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE subscription DROP CONSTRAINT FK_A3C664D332C8A3DE');
        $this->addSql('ALTER TABLE subscription DROP CONSTRAINT FK_A3C664D32B18554A');
        $this->addSql('DROP INDEX idx_subscription_organization');
        $this->addSql('DROP INDEX idx_subscription_owner_user');
        $this->addSql('ALTER TABLE subscription DROP organization_id');
        $this->addSql('ALTER TABLE subscription DROP owner_user_id');
        $this->addSql('ALTER TABLE subscription ALTER space_id SET NOT NULL');
    }
}
