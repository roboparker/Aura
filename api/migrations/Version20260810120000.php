<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI agents, step 1 (#827): flag a `user` row as an agent rather than a person.
 *
 * `DEFAULT false` is what makes this a no-op for every existing account and
 * for every path that constructs a User without knowing agents exist — signup,
 * SSO, invite acceptance, fixtures, CLI seeders. Only
 * `App\Service\AgentProvisioner` ever sets it true, and nothing accepts it over
 * the wire, so the flag cannot be acquired by a request.
 *
 * No other schema change is needed, which is the whole argument for the design:
 * an agent's membership, roles and credential are ordinary `space_membership`,
 * `space_membership_role` and `api_token` rows.
 */
final class Version20260810120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI agents step 1: add user.is_agent.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD is_agent BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP is_agent');
    }
}
