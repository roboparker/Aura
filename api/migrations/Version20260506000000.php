<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drops the `greeting` table left over from the API Platform skeleton.
 * The corresponding entity, test, and admin resource registration are
 * removed in the same change. Closes #154.
 */
final class Version20260506000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the leftover `greeting` skeleton table (#154).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE greeting');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE greeting (id UUID NOT NULL, name VARCHAR(255) NOT NULL, PRIMARY KEY (id))');
    }
}
