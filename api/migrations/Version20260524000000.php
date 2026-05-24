<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Replace `user.totp_recovery_code_hashes` with `user.totp_recovery_codes`.
 *
 * Old shape: `["<sha256>", "<sha256>", ...]` — hash-only, codes lost on use.
 * New shape: `[{"hash": "<sha256>", "consumedAt": "2026-...|null"}, ...]`
 *
 * The structural win is `consumedAt`: spent codes stay on the row instead
 * of being filtered out, so the security panel can render them
 * struck-through alongside the still-spendable ones. Plaintext stays
 * hash-only — recoverable codes would collapse the second factor into
 * single-factor whenever the password was compromised (an attacker with
 * the password could log in once, reveal an unused code, then bypass TOTP
 * forever from any device).
 *
 * Backfill: every existing hash becomes `{hash, consumedAt: null}`.
 */
final class Version20260524000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Replace user.totp_recovery_code_hashes with the structured user.totp_recovery_codes column.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN totp_recovery_codes JSON DEFAULT NULL');

        // Backfill: wrap each legacy hash in the new {hash, consumedAt}
        // shape. jsonb_build_object would be nicer but the column is plain
        // json — stick to json_build_object for compatibility, then
        // aggregate back into a list per row.
        $this->addSql(<<<'SQL'
            UPDATE "user"
            SET totp_recovery_codes = sub.codes
            FROM (
                SELECT u.id AS user_id,
                       COALESCE(
                           json_agg(
                               json_build_object(
                                   'hash', hash_value,
                                   'consumedAt', NULL
                               )
                           ) FILTER (WHERE hash_value IS NOT NULL),
                           '[]'::json
                       ) AS codes
                FROM "user" u
                LEFT JOIN LATERAL json_array_elements_text(u.totp_recovery_code_hashes) AS hash_value ON TRUE
                WHERE u.totp_recovery_code_hashes IS NOT NULL
                GROUP BY u.id
            ) AS sub
            WHERE "user".id = sub.user_id
        SQL);

        $this->addSql('ALTER TABLE "user" DROP COLUMN totp_recovery_code_hashes');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN totp_recovery_code_hashes JSON DEFAULT NULL');

        // Reverse: collect every non-consumed entry's hash into the legacy
        // list shape. Consumed entries are dropped (the old schema had no
        // place for them). Plaintext is lost regardless of encryption state.
        $this->addSql(<<<'SQL'
            UPDATE "user"
            SET totp_recovery_code_hashes = sub.hashes
            FROM (
                SELECT u.id AS user_id,
                       COALESCE(
                           json_agg(entry->>'hash') FILTER (WHERE entry->>'consumedAt' IS NULL),
                           '[]'::json
                       ) AS hashes
                FROM "user" u
                LEFT JOIN LATERAL json_array_elements(u.totp_recovery_codes) AS entry ON TRUE
                WHERE u.totp_recovery_codes IS NOT NULL
                GROUP BY u.id
            ) AS sub
            WHERE "user".id = sub.user_id
        SQL);

        $this->addSql('ALTER TABLE "user" DROP COLUMN totp_recovery_codes');
    }
}
