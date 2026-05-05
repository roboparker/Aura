<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds a Postgres full-text search vector + GIN index to
 * `custom_field_value` so the task search filter can also match against
 * custom field values (text, dropdown selection, number, date) — closing
 * the last gap in #87's "search across custom field values" criterion.
 *
 * `value` is stored as JSON (any of string|number|bool|null), so we
 * extract the unquoted text representation with `#>> '{}'` before
 * tokenising. That returns:
 *   - the raw string body for text / dropdown values
 *   - the digits for numbers
 *   - the ISO `YYYY-MM-DD` for dates
 *   - `true` / `false` for checkboxes (low-signal but harmless)
 *
 * Both the `#>>` operator on json and `to_tsvector(regconfig, text)`
 * are IMMUTABLE in Postgres, so the expression is safe inside a STORED
 * generated column.
 */
final class Version20260505000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tsvector search_vector + GIN index to custom_field_value (#87).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE custom_field_value
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                to_tsvector('english', coalesce(value #>> '{}', ''))
            ) STORED
        SQL);
        $this->addSql('CREATE INDEX idx_cfv_search_vector ON custom_field_value USING GIN (search_vector)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_cfv_search_vector');
        $this->addSql('ALTER TABLE custom_field_value DROP COLUMN search_vector');
    }
}
