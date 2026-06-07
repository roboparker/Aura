<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Postgres-backed job queue for Symfony Messenger's Doctrine transport.
 *
 * This is the table the transport would otherwise create via `auto_setup`;
 * we provision it by migration instead (auto_setup is disabled in
 * MESSENGER_TRANSPORT_DSN) so the queue lives in the same schema-as-code
 * flow as the rest of the database. The ORM is told to ignore the table via
 * doctrine.dbal.schema_filter so doctrine:schema:validate stays green.
 *
 * The trigger + function reproduce what the Doctrine PostgreSQL transport
 * installs for LISTEN/NOTIFY: an INSERT/UPDATE fires `pg_notify` so the
 * `messenger:consume` worker wakes immediately instead of waiting for its
 * next poll.
 */
final class Version20260606140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create messenger_messages table for the Postgres-backed job queue.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
                id BIGSERIAL NOT NULL,
                body TEXT NOT NULL,
                headers TEXT NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)');
        $this->addSql("COMMENT ON COLUMN messenger_messages.created_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN messenger_messages.available_at IS '(DC2Type:datetime_immutable)'");
        $this->addSql("COMMENT ON COLUMN messenger_messages.delivered_at IS '(DC2Type:datetime_immutable)'");

        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION notify_messenger_messages() RETURNS TRIGGER AS $$
                BEGIN
                    PERFORM pg_notify('messenger_messages', NEW.queue_name::text);
                    RETURN NEW;
                END;
            $$ LANGUAGE plpgsql;
        SQL);
        $this->addSql('DROP TRIGGER IF EXISTS notify_trigger ON messenger_messages');
        $this->addSql(<<<'SQL'
            CREATE TRIGGER notify_trigger
                AFTER INSERT OR UPDATE ON messenger_messages
                FOR EACH ROW EXECUTE PROCEDURE notify_messenger_messages();
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS notify_trigger ON messenger_messages');
        $this->addSql('DROP FUNCTION IF EXISTS notify_messenger_messages()');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
