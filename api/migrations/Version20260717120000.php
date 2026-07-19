<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rename the last legacy `project`-named indexes to `board`/`engagement`
 * (post Project→Board split; pre-release, no BC). Pure identifier renames —
 * no data or column changes.
 */
final class Version20260717120000 extends AbstractMigration
{
    /** @var array<string, string> old index name => new index name */
    private const RENAMES = [
        'idx_project_owner' => 'idx_board_owner',
        'idx_project_space' => 'idx_board_space',
        'idx_project_search_vector' => 'idx_board_search_vector',
        'idx_engagement_category_project' => 'idx_engagement_category_engagement',
        'idx_task_project' => 'idx_task_board',
        'idx_task_section_project_position' => 'idx_task_section_board_position',
        'uniq_pfv_project_definition' => 'uniq_pfv_board_definition',
        'uniq_pfv_project_global_definition' => 'uniq_pfv_board_global_definition',
    ];

    public function getDescription(): string
    {
        return 'Rename legacy project_* indexes to board_*/engagement_* (Project→Board cleanup).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->addSql(sprintf('ALTER INDEX %s RENAME TO %s', $old, $new));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::RENAMES as $old => $new) {
            $this->addSql(sprintf('ALTER INDEX %s RENAME TO %s', $new, $old));
        }
    }
}
