<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Re-scope tags from per-user to per-space (#tags).
 *
 * Tags gain a required `space_id` FK so every member of a space shares its tag
 * pool (matching discussions/pages). Existing tags are backfilled to their
 * owner's personal space — the space that owner already worked in — before the
 * NOT NULL constraint lands. Access is now enforced by TagAccessExtension
 * (space membership) instead of TagOwnerExtension (owner).
 */
final class Version20260625120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Scope tags to a space: add tag.space_id (backfilled to each owner\'s personal space).';
    }

    public function up(Schema $schema): void
    {
        // 1. Add the column nullable so existing rows survive the ALTER.
        $this->addSql('ALTER TABLE tag ADD space_id UUID DEFAULT NULL');

        // 2. Backfill each tag to its owner's personal space.
        $this->addSql(
            'UPDATE tag t SET space_id = ('
            . 'SELECT s.id FROM space s '
            . 'WHERE s.created_by_id = t.owner_id AND s.is_personal = true LIMIT 1)',
        );

        // 3. Drop any tag whose owner has no personal space (e.g. an
        //    anonymised/sentinel owner) so the NOT NULL constraint can apply.
        $this->addSql('DELETE FROM tag WHERE space_id IS NULL');

        // 4. Lock it down: required FK + supporting index.
        $this->addSql('ALTER TABLE tag ALTER COLUMN space_id SET NOT NULL');
        $this->addSql('CREATE INDEX idx_tag_space ON tag (space_id)');
        $this->addSql(
            'ALTER TABLE tag ADD CONSTRAINT fk_tag_space '
            . 'FOREIGN KEY (space_id) REFERENCES space (id) '
            . 'ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tag DROP CONSTRAINT IF EXISTS fk_tag_space');
        $this->addSql('DROP INDEX IF EXISTS idx_tag_space');
        $this->addSql('ALTER TABLE tag DROP COLUMN space_id');
    }
}
