<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260705181148 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename auto FK-index names to match the engagement table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_58562b479a0a700d RENAME TO IDX_58562B47D30F6F97');
        $this->addSql('ALTER INDEX idx_449d5b3923575340 RENAME TO IDX_D86F014123575340');
        $this->addSql('ALTER INDEX idx_449d5b39b03a8386 RENAME TO IDX_D86F0141B03A8386');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_58562b47d30f6f97 RENAME TO idx_58562b479a0a700d');
        $this->addSql('ALTER INDEX idx_d86f014123575340 RENAME TO idx_449d5b3923575340');
        $this->addSql('ALTER INDEX idx_d86f0141b03a8386 RENAME TO idx_449d5b39b03a8386');
    }
}
