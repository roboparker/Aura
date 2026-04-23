<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the Projects feature: a `project` table, a `project_member` join
 * table linking users to projects (all members share full access), and an
 * optional `project_id` foreign key on `task`. A null `project_id` keeps
 * today's personal tasks scoped strictly to their owner; a set value opens
 * the task to every project member.
 */
final class Version20260422130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add project and project_member tables; attach task.project_id for shared access.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE project (id UUID NOT NULL, owner_id UUID NOT NULL, title VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, created_on TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_project_owner ON project (owner_id)');
        $this->addSql('ALTER TABLE project ADD CONSTRAINT FK_2FB3D0EE7E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE project_member (project_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (project_id, user_id))');
        $this->addSql('CREATE INDEX IDX_67401132166D1F9C ON project_member (project_id)');
        $this->addSql('CREATE INDEX IDX_67401132A76ED395 ON project_member (user_id)');
        $this->addSql('ALTER TABLE project_member ADD CONSTRAINT FK_67401132166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE project_member ADD CONSTRAINT FK_67401132A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('ALTER TABLE task ADD project_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_task_project ON task (project_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB25166D1F9C');
        $this->addSql('DROP INDEX idx_task_project');
        $this->addSql('ALTER TABLE task DROP project_id');

        $this->addSql('ALTER TABLE project_member DROP CONSTRAINT FK_67401132A76ED395');
        $this->addSql('ALTER TABLE project_member DROP CONSTRAINT FK_67401132166D1F9C');
        $this->addSql('DROP TABLE project_member');

        $this->addSql('ALTER TABLE project DROP CONSTRAINT FK_2FB3D0EE7E3C61F9');
        $this->addSql('DROP TABLE project');
    }
}
