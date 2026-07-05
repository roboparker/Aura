<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260705173851 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename auto FK-index names to match the board* tables (follows Version20260705173606).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_2fb3d0ee9a0a700d RENAME TO IDX_58562B479A0A700D');
        $this->addSql('ALTER INDEX idx_579049ba166d1f9c RENAME TO IDX_CBDD3B3AE7EC5785');
        $this->addSql('ALTER INDEX idx_579049ba89fd6c40 RENAME TO IDX_CBDD3B3A89FD6C40');
        $this->addSql('ALTER INDEX idx_9a4c797c166d1f9c RENAME TO IDX_345FDA0E7EC5785');
        $this->addSql('ALTER INDEX idx_9a4c797c117a7288 RENAME TO IDX_345FDA0117A7288');
        $this->addSql('ALTER INDEX idx_e183e9d1166d1f9c RENAME TO IDX_7DC6F318E7EC5785');
        $this->addSql('ALTER INDEX idx_32925005166d1f9c RENAME TO IDX_32925005E7EC5785');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_58562b479a0a700d RENAME TO idx_2fb3d0ee9a0a700d');
        $this->addSql('ALTER INDEX idx_cbdd3b3ae7ec5785 RENAME TO idx_579049ba166d1f9c');
        $this->addSql('ALTER INDEX idx_cbdd3b3a89fd6c40 RENAME TO idx_579049ba89fd6c40');
        $this->addSql('ALTER INDEX idx_7dc6f318e7ec5785 RENAME TO idx_e183e9d1166d1f9c');
        $this->addSql('ALTER INDEX idx_345fda0e7ec5785 RENAME TO idx_9a4c797c166d1f9c');
        $this->addSql('ALTER INDEX idx_345fda0117a7288 RENAME TO idx_9a4c797c117a7288');
        $this->addSql('ALTER INDEX idx_32925005e7ec5785 RENAME TO idx_32925005166d1f9c');
    }
}
