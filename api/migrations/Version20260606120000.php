<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Waitlist mode: a per-user `waitlisted` flag plus the `app_setting` key/value
 * table that backs the runtime "is the instance in waitlist mode?" toggle.
 *
 * A waitlisted user holds no ROLE_USER (User::getRoles), so it can sign in and
 * read /api/me but is blocked from everything else until an admin opens
 * signups. Existing users default to `waitlisted = false`, so nobody already
 * registered is affected.
 */
final class Version20260606120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user.waitlisted and the app_setting table for the waitlist-mode toggle.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD waitlisted BOOLEAN DEFAULT FALSE NOT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE app_setting (
                id UUID NOT NULL,
                name VARCHAR(64) NOT NULL,
                value JSON NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_app_setting_name ON app_setting (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_setting');
        $this->addSql('ALTER TABLE "user" DROP waitlisted');
    }
}
