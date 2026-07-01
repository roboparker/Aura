<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Seed the four out-of-the-box global custom fields (#global-custom-fields):
 *
 *   - Effort — numeric.int
 *   - Cost   — numeric.money (USD)
 *   - Time   — numeric.float (hours)
 *   - Status — select.single (Backlog / To do / In progress / Review / Ready / Done)
 *
 * These land unattached — every project can opt into them via its field
 * picker. They stay fully admin-editable / removable afterwards; this only
 * provides sensible defaults on a fresh instance. Idempotent-ish: skips a name
 * that already exists so a re-run (or a manual pre-seed) doesn't collide with
 * the unique-name constraint.
 */
final class Version20260701210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Seed the four default global custom fields (Effort, Cost, Time, Status).';
    }

    public function up(Schema $schema): void
    {
        $statusOptions = [
            ['key' => 'backlog', 'label' => 'Backlog'],
            ['key' => 'to_do', 'label' => 'To do'],
            ['key' => 'in_progress', 'label' => 'In progress'],
            ['key' => 'review', 'label' => 'Review'],
            ['key' => 'ready', 'label' => 'Ready'],
            ['key' => 'done', 'label' => 'Done'],
        ];

        $fields = [
            ['name' => 'Effort', 'kind' => 'numeric', 'subtype' => 'int', 'config' => []],
            ['name' => 'Cost', 'kind' => 'numeric', 'subtype' => 'money', 'config' => ['currency' => 'USD']],
            ['name' => 'Time', 'kind' => 'numeric', 'subtype' => 'float', 'config' => []],
            ['name' => 'Status', 'kind' => 'select', 'subtype' => 'single', 'config' => ['multi' => false, 'options' => $statusOptions]],
        ];

        $position = 0;
        foreach ($fields as $field) {
            $config = json_encode($field['config'], JSON_THROW_ON_ERROR);
            $this->addSql(
                <<<'SQL'
                    INSERT INTO global_custom_field_definition
                        (id, name, kind, subtype, config, footer, nullable, position, visibility, created_at)
                    SELECT gen_random_uuid(), :name, :kind, :subtype, CAST(:config AS JSON), NULL, true, :position, 'both', now()
                    WHERE NOT EXISTS (
                        SELECT 1 FROM global_custom_field_definition WHERE name = :name
                    )
                    SQL,
                [
                    'name' => $field['name'],
                    'kind' => $field['kind'],
                    'subtype' => $field['subtype'],
                    'config' => $config,
                    'position' => $position,
                ],
            );
            ++$position;
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "DELETE FROM global_custom_field_definition WHERE name IN ('Effort', 'Cost', 'Time', 'Status')",
        );
    }
}
