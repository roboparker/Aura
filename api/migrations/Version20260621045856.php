<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260621045856 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add task_section (board sections) and task.section_id; null section = default "In progress" group.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE task_section (id UUID NOT NULL, title VARCHAR(120) NOT NULL, position INT NOT NULL, project_id UUID NOT NULL, space_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_32925005166D1F9C ON task_section (project_id)');
        $this->addSql('CREATE INDEX idx_task_section_project_position ON task_section (project_id, position)');
        $this->addSql('CREATE INDEX idx_task_section_space ON task_section (space_id)');
        $this->addSql('ALTER TABLE task_section ADD CONSTRAINT FK_32925005166D1F9C FOREIGN KEY (project_id) REFERENCES project (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task_section ADD CONSTRAINT FK_3292500523575340 FOREIGN KEY (space_id) REFERENCES space (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE task ADD section_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE task ADD CONSTRAINT FK_527EDB25D823E37A FOREIGN KEY (section_id) REFERENCES task_section (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_527EDB25D823E37A ON task (section_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE task_section DROP CONSTRAINT FK_32925005166D1F9C');
        $this->addSql('ALTER TABLE task_section DROP CONSTRAINT FK_3292500523575340');
        $this->addSql('DROP TABLE task_section');
        $this->addSql('ALTER TABLE task DROP CONSTRAINT FK_527EDB25D823E37A');
        $this->addSql('DROP INDEX IDX_527EDB25D823E37A');
        $this->addSql('ALTER TABLE task DROP section_id');
    }
}
