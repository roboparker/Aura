<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename the task-management "Project" to "Board" (the billing side keeps the
 * "Project" name). Data-preserving: RENAME the tables + FK columns rather than
 * drop/create, so no board/task data is lost.
 */
final class Version20260705173606 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename project* tables/columns to board* (task-management Project → Board).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project RENAME TO board');
        $this->addSql('ALTER TABLE project_custom_field_definition RENAME TO board_custom_field_definition');
        $this->addSql('ALTER TABLE board_custom_field_definition RENAME COLUMN project_id TO board_id');
        $this->addSql('ALTER TABLE project_global_custom_field_definition RENAME TO board_global_custom_field_definition');
        $this->addSql('ALTER TABLE board_global_custom_field_definition RENAME COLUMN project_id TO board_id');
        $this->addSql('ALTER TABLE project_field_visibility RENAME TO board_field_visibility');
        $this->addSql('ALTER TABLE board_field_visibility RENAME COLUMN project_id TO board_id');
        $this->addSql('ALTER TABLE task RENAME COLUMN project_id TO board_id');
        $this->addSql('ALTER TABLE task_section RENAME COLUMN project_id TO board_id');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_section RENAME COLUMN board_id TO project_id');
        $this->addSql('ALTER TABLE task RENAME COLUMN board_id TO project_id');
        $this->addSql('ALTER TABLE board_field_visibility RENAME COLUMN board_id TO project_id');
        $this->addSql('ALTER TABLE board_field_visibility RENAME TO project_field_visibility');
        $this->addSql('ALTER TABLE board_global_custom_field_definition RENAME COLUMN board_id TO project_id');
        $this->addSql('ALTER TABLE board_global_custom_field_definition RENAME TO project_global_custom_field_definition');
        $this->addSql('ALTER TABLE board_custom_field_definition RENAME COLUMN board_id TO project_id');
        $this->addSql('ALTER TABLE board_custom_field_definition RENAME TO project_custom_field_definition');
        $this->addSql('ALTER TABLE board RENAME TO project');
    }
}
