<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds user profile pictures: a shared `media_object` table, user name
 * fields (given/family/nickname), a `personalized_color` for the initials
 * fallback, and an optional `avatar_id` foreign key.
 */
final class Version20260423120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add media_object table; add user name/color/avatar columns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE media_object (id UUID NOT NULL, owner_id UUID NOT NULL, kind VARCHAR(32) NOT NULL, variants JSON NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(100) NOT NULL, byte_size INT NOT NULL, created_on TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_media_object_owner ON media_object (owner_id)');
        $this->addSql('ALTER TABLE media_object ADD CONSTRAINT FK_MEDIA_OBJECT_OWNER FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql("ALTER TABLE \"user\" ADD given_name VARCHAR(100) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE \"user\" ADD family_name VARCHAR(100) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE "user" ADD nickname VARCHAR(100) DEFAULT NULL');
        $this->addSql("ALTER TABLE \"user\" ADD personalized_color VARCHAR(7) NOT NULL DEFAULT '#1e6091'");
        $this->addSql('ALTER TABLE "user" ALTER COLUMN given_name DROP DEFAULT');
        $this->addSql('ALTER TABLE "user" ALTER COLUMN family_name DROP DEFAULT');
        $this->addSql('ALTER TABLE "user" ALTER COLUMN personalized_color DROP DEFAULT');

        $this->addSql('ALTER TABLE "user" ADD avatar_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD CONSTRAINT FK_USER_AVATAR FOREIGN KEY (avatar_id) REFERENCES media_object (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_user_avatar ON "user" (avatar_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP CONSTRAINT FK_USER_AVATAR');
        $this->addSql('DROP INDEX idx_user_avatar');
        $this->addSql('ALTER TABLE "user" DROP avatar_id');

        $this->addSql('ALTER TABLE "user" DROP given_name');
        $this->addSql('ALTER TABLE "user" DROP family_name');
        $this->addSql('ALTER TABLE "user" DROP nickname');
        $this->addSql('ALTER TABLE "user" DROP personalized_color');

        $this->addSql('ALTER TABLE media_object DROP CONSTRAINT FK_MEDIA_OBJECT_OWNER');
        $this->addSql('DROP INDEX idx_media_object_owner');
        $this->addSql('DROP TABLE media_object');
    }
}
