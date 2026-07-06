<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260704210038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Organizations above Spaces (#billing Phase 1a): entities + backfill one org per shared space.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE organization (id UUID NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_by_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C1EE637CB03A8386 ON organization (created_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_organization_slug ON organization (slug)');
        $this->addSql('CREATE TABLE organization_membership (id UUID NOT NULL, role VARCHAR(16) DEFAULT \'member\' NOT NULL, joined_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6FA4406A32C8A3DE ON organization_membership (organization_id)');
        $this->addSql('CREATE INDEX idx_organization_membership_user ON organization_membership (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_organization_membership_user ON organization_membership (organization_id, user_id)');
        $this->addSql('ALTER TABLE organization ADD CONSTRAINT FK_C1EE637CB03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE organization_membership ADD CONSTRAINT FK_6FA4406A32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE organization_membership ADD CONSTRAINT FK_6FA4406AA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE space ADD organization_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE space ADD CONSTRAINT FK_2972C13A32C8A3DE FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_2972C13A32C8A3DE ON space (organization_id)');

        // Backfill: wrap every existing shared (non-personal) space in its own
        // Organization. Owner = the space creator (or, if that user is gone, the
        // earliest admin/member); every direct space member becomes an org
        // member (Member, or Owner for the resolved owner). Personal spaces stay
        // org-less. Behaviour is unchanged — PlanGate still reads the space's own
        // subscription until Phase 1b moves billing to the org.
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                s RECORD;
                org_id uuid;
                owner_id uuid;
            BEGIN
                FOR s IN SELECT id, name, created_by_id FROM space
                         WHERE is_personal = false AND organization_id IS NULL LOOP
                    owner_id := COALESCE(
                        s.created_by_id,
                        (SELECT sm.user_id FROM space_membership sm WHERE sm.space_id = s.id
                         ORDER BY (sm.role = 'admin') DESC, sm.joined_at ASC LIMIT 1)
                    );
                    IF owner_id IS NULL THEN
                        CONTINUE; -- ownerless + memberless shared space; leave org-less
                    END IF;

                    org_id := gen_random_uuid();
                    INSERT INTO organization (id, name, slug, created_by_id, created_at, updated_at)
                    VALUES (org_id, s.name,
                            'o-' || substr(replace(org_id::text, '-', ''), 1, 12),
                            owner_id, now(), now());
                    UPDATE space SET organization_id = org_id WHERE id = s.id;

                    INSERT INTO organization_membership (id, organization_id, user_id, role, joined_at)
                    SELECT gen_random_uuid(), org_id, sm.user_id,
                           CASE WHEN sm.user_id = owner_id THEN 'owner' ELSE 'member' END, now()
                    FROM space_membership sm WHERE sm.space_id = s.id
                    ON CONFLICT (organization_id, user_id) DO NOTHING;

                    INSERT INTO organization_membership (id, organization_id, user_id, role, joined_at)
                    VALUES (gen_random_uuid(), org_id, owner_id, 'owner', now())
                    ON CONFLICT (organization_id, user_id) DO UPDATE SET role = 'owner';
                END LOOP;
            END $$;
            SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE organization DROP CONSTRAINT FK_C1EE637CB03A8386');
        $this->addSql('ALTER TABLE organization_membership DROP CONSTRAINT FK_6FA4406A32C8A3DE');
        $this->addSql('ALTER TABLE organization_membership DROP CONSTRAINT FK_6FA4406AA76ED395');
        $this->addSql('DROP TABLE organization');
        $this->addSql('DROP TABLE organization_membership');
        $this->addSql('ALTER TABLE space DROP CONSTRAINT FK_2972C13A32C8A3DE');
        $this->addSql('DROP INDEX IDX_2972C13A32C8A3DE');
        $this->addSql('ALTER TABLE space DROP organization_id');
    }
}
