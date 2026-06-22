<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * In-app feedback board (#feedback): bug reports + feature requests.
 *
 * Instance-level, not space-scoped — every authenticated user shares one
 * board. `feedback` holds the tickets; `feedback_vote` the one-per-user
 * up/down votes; `feedback_ticket_seq` hands out the monotonic,
 * concurrency-safe human-facing ticket numbers (App\State\FeedbackOwnerProcessor
 * pulls nextval on create). The sequence is excluded from Doctrine schema
 * introspection in doctrine.yaml so schema:validate doesn't flag it as
 * untracked.
 */
final class Version20260622120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add feedback + feedback_vote tables and the feedback ticket-number sequence.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE SEQUENCE feedback_ticket_seq INCREMENT BY 1 MINVALUE 1 START 1');

        $this->addSql(<<<'SQL'
            CREATE TABLE feedback (
                id UUID NOT NULL,
                owner_id UUID NOT NULL,
                ticket_number INT NOT NULL,
                title VARCHAR(200) NOT NULL,
                description TEXT NOT NULL,
                type VARCHAR(16) NOT NULL,
                status VARCHAR(16) DEFAULT 'open' NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_feedback_ticket_number ON feedback (ticket_number)');
        $this->addSql('CREATE INDEX idx_feedback_owner ON feedback (owner_id)');
        $this->addSql('CREATE INDEX idx_feedback_status ON feedback (status)');
        $this->addSql('CREATE INDEX idx_feedback_type ON feedback (type)');
        $this->addSql(<<<'SQL'
            ALTER TABLE feedback
                ADD CONSTRAINT fk_feedback_owner FOREIGN KEY (owner_id)
                REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE feedback_vote (
                id UUID NOT NULL,
                feedback_id UUID NOT NULL,
                user_id UUID NOT NULL,
                value SMALLINT NOT NULL,
                voted_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_feedback_vote ON feedback_vote (feedback_id, user_id)');
        $this->addSql('CREATE INDEX idx_feedback_vote_feedback ON feedback_vote (feedback_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE feedback_vote
                ADD CONSTRAINT fk_feedback_vote_feedback FOREIGN KEY (feedback_id)
                REFERENCES feedback (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE feedback_vote
                ADD CONSTRAINT fk_feedback_vote_user FOREIGN KEY (user_id)
                REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE feedback_vote DROP CONSTRAINT fk_feedback_vote_feedback');
        $this->addSql('ALTER TABLE feedback_vote DROP CONSTRAINT fk_feedback_vote_user');
        $this->addSql('DROP TABLE feedback_vote');
        $this->addSql('ALTER TABLE feedback DROP CONSTRAINT fk_feedback_owner');
        $this->addSql('DROP TABLE feedback');
        $this->addSql('DROP SEQUENCE feedback_ticket_seq');
    }
}
