<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI agents, step 3 (#827): conversation storage.
 *
 * One thread per (agent, person) — the unique index is the invariant, not a
 * performance hint: a shared thread would make everything one colleague said
 * into context the model sees for everybody else.
 *
 * Additive only, and nothing reads these tables until the chat dock ships in
 * the same release, so this is safe to deploy ahead of the UI and safe to roll
 * back.
 *
 * Index/constraint names: the per-FK ones Doctrine generates carry its hashed
 * names, because a *composite* index starting with the same column does not
 * satisfy its comparator — `agent_conversation.agent_id` and
 * `agent_message.conversation_id` each need their own single-column index even
 * though both are already the leading column of one.
 */
final class Version20260810190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI agents step 3: create agent_conversation and agent_message.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE agent_conversation (
                id UUID NOT NULL,
                agent_id UUID NOT NULL,
                user_id UUID NOT NULL,
                space_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_message_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_FCC2A9223414710B ON agent_conversation (agent_id)');
        $this->addSql('CREATE INDEX idx_agent_conversation_user ON agent_conversation (user_id)');
        $this->addSql('CREATE INDEX idx_agent_conversation_space ON agent_conversation (space_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_agent_conversation ON agent_conversation (agent_id, user_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE agent_message (
                id UUID NOT NULL,
                conversation_id UUID NOT NULL,
                role VARCHAR(16) NOT NULL,
                body TEXT NOT NULL,
                truncated BOOLEAN DEFAULT false NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX IDX_64EE52D59AC0396 ON agent_message (conversation_id)');
        $this->addSql('CREATE INDEX idx_agent_message_thread ON agent_message (conversation_id, created_at)');

        // Everything here is a side effect of the agent, the person and the
        // space existing; when any of them goes, so does the conversation.
        $this->addSql(<<<'SQL'
            ALTER TABLE agent_conversation ADD CONSTRAINT FK_FCC2A9223414710B
            FOREIGN KEY (agent_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE agent_conversation ADD CONSTRAINT FK_FCC2A922A76ED395
            FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE agent_conversation ADD CONSTRAINT FK_FCC2A92223575340
            FOREIGN KEY (space_id) REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE agent_message ADD CONSTRAINT FK_64EE52D59AC0396
            FOREIGN KEY (conversation_id) REFERENCES agent_conversation (id) ON DELETE CASCADE NOT DEFERRABLE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE agent_message DROP CONSTRAINT FK_64EE52D59AC0396');
        $this->addSql('ALTER TABLE agent_conversation DROP CONSTRAINT FK_FCC2A9223414710B');
        $this->addSql('ALTER TABLE agent_conversation DROP CONSTRAINT FK_FCC2A922A76ED395');
        $this->addSql('ALTER TABLE agent_conversation DROP CONSTRAINT FK_FCC2A92223575340');
        $this->addSql('DROP TABLE agent_message');
        $this->addSql('DROP TABLE agent_conversation');
    }
}
