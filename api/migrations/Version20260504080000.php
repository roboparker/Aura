<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds TOTP-based 2FA columns to the user table (#86):
 *  - totp_secret_encrypted: ciphertext envelope of the base32 secret
 *  - totp_enabled / totp_enabled_at: status pair so we can tell
 *    "set up but not confirmed" from "active"
 *  - totp_recovery_code_hashes: JSON list of sha256 hashes of remaining
 *    one-time recovery codes
 */
final class Version20260504080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add TOTP 2FA columns to user (#86).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD totp_secret_encrypted TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD totp_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE "user" ADD totp_enabled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD totp_recovery_code_hashes JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP totp_secret_encrypted');
        $this->addSql('ALTER TABLE "user" DROP totp_enabled');
        $this->addSql('ALTER TABLE "user" DROP totp_enabled_at');
        $this->addSql('ALTER TABLE "user" DROP totp_recovery_code_hashes');
    }
}
