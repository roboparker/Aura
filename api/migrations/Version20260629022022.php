<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Built-in Member/Guest roles (#space-roles).
 *
 * Adds `space_role.builtin_key` and backfills, for every existing space, a
 * "Member" role (full access — the editable default for members with no
 * assigned role, so behaviour is preserved until an admin tightens it) and a
 * "Guest" role (read every content category + create comments). Idempotent.
 */
final class Version20260629022022 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add space_role.builtin_key + backfill built-in Member/Guest roles per space.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE space_role ADD builtin_key VARCHAR(16) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_space_role_builtin ON space_role (space_id, builtin_key)');

        // Frozen permission matrices (kept inline so this migration is stable
        // even if the app's preset helpers change later).
        $categories = ['projects', 'tasks', 'pages', 'discussions', 'comments', 'custom_fields', 'tags', 'groups', 'files'];
        $allTrue = ['create' => true, 'read' => true, 'update' => true, 'delete' => true];
        $memberJson = (string) json_encode(array_fill_keys($categories, $allTrue));

        $guest = array_fill_keys($categories, ['create' => false, 'read' => true, 'update' => false, 'delete' => false]);
        $guest['comments']['create'] = true;
        $guestJson = (string) json_encode($guest);

        $this->addSql(
            <<<'SQL'
                INSERT INTO space_role (id, space_id, name, color, permissions, created_at, builtin_key)
                SELECT gen_random_uuid(), s.id, 'Member', '#64748b', CAST(? AS JSON), now(), 'member'
                FROM space s
                WHERE NOT EXISTS (SELECT 1 FROM space_role r WHERE r.space_id = s.id AND r.builtin_key = 'member')
                SQL,
            [$memberJson],
        );
        $this->addSql(
            <<<'SQL'
                INSERT INTO space_role (id, space_id, name, color, permissions, created_at, builtin_key)
                SELECT gen_random_uuid(), s.id, 'Guest', '#a1a1aa', CAST(? AS JSON), now(), 'guest'
                FROM space s
                WHERE NOT EXISTS (SELECT 1 FROM space_role r WHERE r.space_id = s.id AND r.builtin_key = 'guest')
                SQL,
            [$guestJson],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM space_role WHERE builtin_key IN ('member', 'guest')");
        $this->addSql('DROP INDEX uniq_space_role_builtin');
        $this->addSql('ALTER TABLE space_role DROP builtin_key');
    }
}
