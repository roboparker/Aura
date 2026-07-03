<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Polymorphic custom-field values: a task value can now point at EITHER a
 * space-owned `custom_field_definition` OR the instance-wide
 * `global_custom_field_definition`.
 *
 * Adds a nullable `global_definition_id` FK alongside the (now nullable)
 * `definition_id`, with a CHECK enforcing exactly one is set (mirrors the
 * `Comment` polymorphic-parent pattern). The global uniqueness constraint is
 * DEFERRABLE INITIALLY DEFERRED like its space sibling so PATCH's
 * delete-then-insert of the whole value set collapses cleanly in one flush.
 * Both partial cases coexist because NULLs are distinct in a unique
 * constraint by default — a global-value row has `definition_id = NULL`
 * (many allowed) and vice-versa.
 */
final class Version20260701203735 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add polymorphic global_definition_id to custom_field_value (XOR with definition_id).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE custom_field_value ADD global_definition_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE custom_field_value ALTER definition_id DROP NOT NULL');
        $this->addSql('ALTER TABLE custom_field_value ADD CONSTRAINT FK_EC7B05C3AA8C40 FOREIGN KEY (global_definition_id) REFERENCES global_custom_field_definition (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_cfv_global_definition ON custom_field_value (global_definition_id)');
        // Deferrable so a PATCH that rebuilds the value set (delete-then-insert)
        // doesn't trip uniqueness mid-transaction — same shape as the existing
        // uniq_cfv_task_definition constraint.
        $this->addSql('ALTER TABLE custom_field_value ADD CONSTRAINT uniq_cfv_task_global_definition UNIQUE (task_id, global_definition_id) DEFERRABLE INITIALLY DEFERRED');
        // Exactly one definition source per value.
        $this->addSql(<<<'SQL'
            ALTER TABLE custom_field_value
            ADD CONSTRAINT chk_cfv_definition_exactly_one
            CHECK (
                (definition_id IS NOT NULL AND global_definition_id IS NULL)
                OR (definition_id IS NULL AND global_definition_id IS NOT NULL)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE custom_field_value DROP CONSTRAINT chk_cfv_definition_exactly_one');
        $this->addSql('ALTER TABLE custom_field_value DROP CONSTRAINT uniq_cfv_task_global_definition');
        $this->addSql('ALTER TABLE custom_field_value DROP CONSTRAINT FK_EC7B05C3AA8C40');
        $this->addSql('DROP INDEX idx_cfv_global_definition');
        $this->addSql('ALTER TABLE custom_field_value ALTER definition_id SET NOT NULL');
        $this->addSql('ALTER TABLE custom_field_value DROP global_definition_id');
    }
}
