<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds custom_field_value for per-task storage of CustomFieldDefinition
 * values (#84). Pairs with the definitions migration
 * (Version20260504060000); together they wire up the full custom-fields
 * surface — definitions per project, values per task.
 */
final class Version20260504090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add custom_field_value table for per-task custom field storage (#84).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE custom_field_value (
                id UUID NOT NULL,
                task_id UUID NOT NULL,
                definition_id UUID NOT NULL,
                value JSON DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_cfv_task ON custom_field_value (task_id)');
        $this->addSql('CREATE INDEX idx_cfv_definition ON custom_field_value (definition_id)');
        // DEFERRABLE INITIALLY DEFERRED so the orphanRemoval-then-insert
        // sequence Doctrine emits on a PATCH that replaces the embedded
        // collection (delete the old row, insert the new one with the
        // same task_id+definition_id pair) commits cleanly. The
        // constraint still fires at COMMIT — duplicate inserts in the
        // same transaction are rejected just as loudly, only later.
        $this->addSql('ALTER TABLE custom_field_value ADD CONSTRAINT uniq_cfv_task_definition UNIQUE (task_id, definition_id) DEFERRABLE INITIALLY DEFERRED');
        $this->addSql('ALTER TABLE custom_field_value ADD CONSTRAINT FK_CFV_TASK FOREIGN KEY (task_id) REFERENCES task (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE custom_field_value ADD CONSTRAINT FK_CFV_DEFINITION FOREIGN KEY (definition_id) REFERENCES custom_field_definition (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE custom_field_value DROP CONSTRAINT FK_CFV_TASK');
        $this->addSql('ALTER TABLE custom_field_value DROP CONSTRAINT FK_CFV_DEFINITION');
        $this->addSql('ALTER TABLE custom_field_value DROP CONSTRAINT uniq_cfv_task_definition');
        $this->addSql('DROP TABLE custom_field_value');
    }
}
