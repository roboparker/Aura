<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Session store for {@see \Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler}.
 *
 * Sessions move from the container filesystem into Postgres so a deploy /
 * container swap no longer logs everyone out — the store lives in the
 * database, which survives the swap. The schema matches PdoSessionHandler's
 * Postgres defaults (`sessions` table, `sess_*` columns); the table is
 * excluded from ORM schema management via `schema_filter` in doctrine.yaml.
 */
final class Version20260624090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the sessions table for database-backed (deploy-resilient) sessions.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS sessions (
            sess_id VARCHAR(128) NOT NULL PRIMARY KEY,
            sess_data BYTEA NOT NULL,
            sess_lifetime INTEGER NOT NULL,
            sess_time INTEGER NOT NULL
        )');
        $this->addSql('CREATE INDEX IF NOT EXISTS sessions_sess_lifetime_idx ON sessions (sess_lifetime)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS sessions');
    }
}
