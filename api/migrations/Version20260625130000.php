<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Re-scope custom fields from per-project to per-space (#custom-fields-space).
 *
 * Fields become owned by a Space (the `space_id` column already exists and is
 * NOT NULL — it was a denormalised cache). Projects opt in to fields via a new
 * `project_custom_field_definition` M2M. The migration:
 *
 *   1. creates the join table,
 *   2. seeds it from each field's current `project_id` (every field starts
 *      attached to the project that owned it),
 *   3. MERGES fields that now collide on `(space_id, name)` — keeps the oldest,
 *      re-points `custom_field_value` + the join rows to it, deletes the dupes,
 *   4. drops `project_id` (and its FK / unique / index), then adds the new
 *      `(space_id, name)` unique + `(space_id, position)` index.
 *
 * The merge step (3) is the only irreversible part — back up before running.
 */
final class Version20260625130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope custom fields to the space; projects attach fields via a M2M (merges (space,name) duplicates).';
    }

    public function up(Schema $schema): void
    {
        // 1. Join table: which projects show which (space-owned) fields.
        $this->addSql('CREATE TABLE project_custom_field_definition (
            project_id UUID NOT NULL,
            custom_field_definition_id UUID NOT NULL,
            PRIMARY KEY(project_id, custom_field_definition_id)
        )');
        // Index names match Doctrine's generated names so schema:validate stays
        // green (M2M join-table indexes can't be named via entity attributes).
        $this->addSql('CREATE INDEX IDX_579049BA166D1F9C ON project_custom_field_definition (project_id)');
        $this->addSql('CREATE INDEX IDX_579049BA89FD6C40 ON project_custom_field_definition (custom_field_definition_id)');
        $this->addSql(
            'ALTER TABLE project_custom_field_definition ADD CONSTRAINT fk_pcfd_project '
            . 'FOREIGN KEY (project_id) REFERENCES project (id) '
            . 'ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE',
        );
        $this->addSql(
            'ALTER TABLE project_custom_field_definition ADD CONSTRAINT fk_pcfd_cfd '
            . 'FOREIGN KEY (custom_field_definition_id) REFERENCES custom_field_definition (id) '
            . 'ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE',
        );

        // Defensive: every field already has a space (NOT NULL cache), but
        // backfill from the project just in case.
        $this->addSql(
            'UPDATE custom_field_definition c SET space_id = p.space_id '
            . 'FROM project p WHERE c.project_id = p.id AND c.space_id IS NULL',
        );

        // 2. Seed the join: each field attaches to the project it belonged to.
        $this->addSql(
            'INSERT INTO project_custom_field_definition (project_id, custom_field_definition_id) '
            . 'SELECT project_id, id FROM custom_field_definition',
        );

        // 3. Merge (space_id, name) duplicates. Survivor = oldest row.
        $this->addSql(
            'CREATE TEMPORARY TABLE cfd_survivor AS '
            . 'SELECT id, FIRST_VALUE(id) OVER ('
            . 'PARTITION BY space_id, name ORDER BY created_at ASC, id ASC) AS survivor_id '
            . 'FROM custom_field_definition',
        );
        // Drop values that would collide on the survivor's (task, definition)
        // unique, then re-point the rest.
        $this->addSql(
            'DELETE FROM custom_field_value v USING cfd_survivor s '
            . 'WHERE v.definition_id = s.id AND s.id <> s.survivor_id '
            . 'AND EXISTS (SELECT 1 FROM custom_field_value v2 '
            . 'WHERE v2.task_id = v.task_id AND v2.definition_id = s.survivor_id)',
        );
        $this->addSql(
            'UPDATE custom_field_value v SET definition_id = s.survivor_id '
            . 'FROM cfd_survivor s WHERE v.definition_id = s.id AND s.id <> s.survivor_id',
        );
        // Re-point join rows onto the survivor (deduping the PK), drop the rest.
        $this->addSql(
            'INSERT INTO project_custom_field_definition (project_id, custom_field_definition_id) '
            . 'SELECT DISTINCT j.project_id, s.survivor_id '
            . 'FROM project_custom_field_definition j '
            . 'JOIN cfd_survivor s ON j.custom_field_definition_id = s.id '
            . 'WHERE s.id <> s.survivor_id ON CONFLICT DO NOTHING',
        );
        $this->addSql(
            'DELETE FROM project_custom_field_definition j USING cfd_survivor s '
            . 'WHERE j.custom_field_definition_id = s.id AND s.id <> s.survivor_id',
        );
        // Remove the now-merged duplicate definitions.
        $this->addSql(
            'DELETE FROM custom_field_definition c USING cfd_survivor s '
            . 'WHERE c.id = s.id AND s.id <> s.survivor_id',
        );
        $this->addSql('DROP TABLE cfd_survivor');

        // 4. Drop the old project parent (CASCADE removes FK_CFD_PROJECT,
        //    uniq_cfd_project_name, idx_cfd_project_position).
        $this->addSql('ALTER TABLE custom_field_definition DROP COLUMN project_id CASCADE');

        // New space-level unique + ordering index (idx_cfd_space already exists).
        $this->addSql('CREATE INDEX idx_cfd_space_position ON custom_field_definition (space_id, position)');
        $this->addSql('CREATE UNIQUE INDEX uniq_cfd_space_name ON custom_field_definition (space_id, name)');
    }

    public function down(Schema $schema): void
    {
        // Best-effort: merged duplicates can't be un-merged. Re-add a single
        // project parent from whichever project the field is attached to.
        $this->addSql('DROP INDEX IF EXISTS uniq_cfd_space_name');
        $this->addSql('DROP INDEX IF EXISTS idx_cfd_space_position');
        $this->addSql('ALTER TABLE custom_field_definition ADD project_id UUID DEFAULT NULL');
        $this->addSql(
            'UPDATE custom_field_definition c SET project_id = ('
            . 'SELECT j.project_id FROM project_custom_field_definition j '
            . 'WHERE j.custom_field_definition_id = c.id LIMIT 1)',
        );
        $this->addSql('DROP TABLE project_custom_field_definition');
        $this->addSql('CREATE INDEX idx_cfd_project_position ON custom_field_definition (project_id, position)');
        $this->addSql(
            'ALTER TABLE custom_field_definition ADD CONSTRAINT FK_CFD_PROJECT '
            . 'FOREIGN KEY (project_id) REFERENCES project (id) '
            . 'ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE',
        );
    }
}
