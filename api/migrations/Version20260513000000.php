<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widens custom_field_definition from a flat 5-type enum
 * (text/number/date/dropdown/checkbox) to a strategy-friendly
 * (kind, subtype, config, footer, nullable) shape (#227).
 *
 * Steps, in order:
 *   1. Add `kind`, `subtype`, `config`, `footer`, `nullable` columns
 *      (kind/subtype nullable until backfilled; config defaults to
 *      '{}'; nullable defaults to true).
 *   2. Backfill from legacy `type` + `options`:
 *        text     → (text,     text,    {multi:false})
 *        number   → (numeric,  float,   {multi:false})
 *        date     → (date,     date,    {multi:false})
 *        dropdown → (select,   single,  {options:[{key,label}…], multi:false})
 *        checkbox → (boolean,  boolean, {})
 *      `nullable` is set to `NOT required` so the inverse semantics
 *      survive untouched.
 *   3. Tighten kind/subtype to NOT NULL once every row has a value.
 *   4. Drop legacy `type` and `options` columns. The PR's locked
 *      decision is "same migration" — the entity exposes derived
 *      `getType()` / `getOptions()` shims during the rest of the PR so
 *      the validator, MCP serializer, and ProjectCopy controller keep
 *      working until they're rewritten on top of the strategy registry.
 *   5. Add `value_search` TEXT column to `custom_field_value` (written
 *      from PHP by the strategies' `searchText()` projection) and
 *      regenerate the STORED `search_vector` to derive from it. The
 *      old generator pulled the raw JSON via `value #>> '{}'`, which
 *      can't reach a reference's display label or a money amount's
 *      currency — projection-via-PHP fixes that without dragging the
 *      strategy logic into SQL.
 *
 * Reversible: backfills are inverted (kind/subtype/config → flat type +
 * options) before columns are dropped on `down()`.
 */
final class Version20260513000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Custom field kinds: kind/subtype/config/footer/nullable + value_search FTS projection (#227).';
    }

    public function up(Schema $schema): void
    {
        // 1. New columns on custom_field_definition.
        $this->addSql(<<<'SQL'
            ALTER TABLE custom_field_definition
                ADD COLUMN kind VARCHAR(16),
                ADD COLUMN subtype VARCHAR(24),
                ADD COLUMN config JSON NOT NULL DEFAULT '{}',
                ADD COLUMN footer JSON DEFAULT NULL,
                ADD COLUMN nullable BOOLEAN NOT NULL DEFAULT TRUE
        SQL);

        // 2. Backfill from legacy type + options.
        $this->addSql(<<<'SQL'
            UPDATE custom_field_definition
            SET kind = CASE type
                    WHEN 'text'     THEN 'text'
                    WHEN 'number'   THEN 'numeric'
                    WHEN 'date'     THEN 'date'
                    WHEN 'dropdown' THEN 'select'
                    WHEN 'checkbox' THEN 'boolean'
                END,
                subtype = CASE type
                    WHEN 'text'     THEN 'text'
                    WHEN 'number'   THEN 'float'
                    WHEN 'date'     THEN 'date'
                    WHEN 'dropdown' THEN 'single'
                    WHEN 'checkbox' THEN 'boolean'
                END,
                config = CASE
                    WHEN type = 'dropdown' AND options IS NOT NULL THEN
                        jsonb_build_object(
                            'multi', false,
                            'options', (
                                SELECT COALESCE(
                                    jsonb_agg(jsonb_build_object('key', opt, 'label', opt) ORDER BY ord),
                                    '[]'::jsonb
                                )
                                FROM jsonb_array_elements_text(options::jsonb) WITH ORDINALITY AS o(opt, ord)
                            )
                        )::json
                    WHEN type = 'checkbox' THEN '{}'::json
                    ELSE jsonb_build_object('multi', false)::json
                END,
                nullable = NOT required
        SQL);

        // 3. NOT NULL on kind/subtype now that every row is filled.
        $this->addSql('ALTER TABLE custom_field_definition ALTER COLUMN kind SET NOT NULL');
        $this->addSql('ALTER TABLE custom_field_definition ALTER COLUMN subtype SET NOT NULL');

        // 4. Drop legacy columns.
        $this->addSql('ALTER TABLE custom_field_definition DROP COLUMN type');
        $this->addSql('ALTER TABLE custom_field_definition DROP COLUMN options');

        // 5. Custom field value: regenerate the FTS projection to read
        //    from a PHP-written `value_search` column so strategies can
        //    project references / money / dates into searchable text.
        //
        //    Drop the existing STORED generated column first (its index
        //    goes with it), add `value_search`, backfill it from the
        //    old `value #>> '{}'` projection so existing rows stay
        //    searchable, then re-add `search_vector` as a generator of
        //    `value_search` and rebuild the GIN index.
        $this->addSql('DROP INDEX idx_cfv_search_vector');
        $this->addSql('ALTER TABLE custom_field_value DROP COLUMN search_vector');
        $this->addSql('ALTER TABLE custom_field_value ADD COLUMN value_search TEXT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE custom_field_value
            SET value_search = value #>> '{}'
            WHERE value IS NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE custom_field_value
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                to_tsvector('english', coalesce(value_search, ''))
            ) STORED
        SQL);
        $this->addSql('CREATE INDEX idx_cfv_search_vector ON custom_field_value USING GIN (search_vector)');
    }

    public function down(Schema $schema): void
    {
        // Reverse step 5: drop value_search, restore the original
        // `value #>> '{}'` generator.
        $this->addSql('DROP INDEX idx_cfv_search_vector');
        $this->addSql('ALTER TABLE custom_field_value DROP COLUMN search_vector');
        $this->addSql('ALTER TABLE custom_field_value DROP COLUMN value_search');
        $this->addSql(<<<'SQL'
            ALTER TABLE custom_field_value
            ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                to_tsvector('english', coalesce(value #>> '{}', ''))
            ) STORED
        SQL);
        $this->addSql('CREATE INDEX idx_cfv_search_vector ON custom_field_value USING GIN (search_vector)');

        // Reverse steps 1-4: add legacy columns back, invert the
        // backfill, then drop the new ones.
        $this->addSql('ALTER TABLE custom_field_definition ADD COLUMN type VARCHAR(16)');
        $this->addSql('ALTER TABLE custom_field_definition ADD COLUMN options JSON DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE custom_field_definition
            SET type = CASE kind
                    WHEN 'text'      THEN 'text'
                    WHEN 'numeric'   THEN 'number'
                    WHEN 'date'      THEN 'date'
                    WHEN 'select'    THEN 'dropdown'
                    WHEN 'boolean'   THEN 'checkbox'
                    -- Reference is new in #227, has no pre-#227 mapping;
                    -- coerce to 'text' so the legacy enum stays satisfied
                    -- on the way back. Down-migration is a last-resort
                    -- escape hatch — data fidelity here is best-effort.
                    ELSE 'text'
                END,
                options = CASE
                    WHEN kind = 'select' AND (config::jsonb)->'options' IS NOT NULL THEN
                        (
                            SELECT COALESCE(jsonb_agg(opt->>'label'), '[]'::jsonb)::json
                            FROM jsonb_array_elements((config::jsonb)->'options') AS opt
                        )
                    ELSE NULL
                END
        SQL);
        $this->addSql('ALTER TABLE custom_field_definition ALTER COLUMN type SET NOT NULL');
        $this->addSql('ALTER TABLE custom_field_definition DROP COLUMN nullable');
        $this->addSql('ALTER TABLE custom_field_definition DROP COLUMN footer');
        $this->addSql('ALTER TABLE custom_field_definition DROP COLUMN config');
        $this->addSql('ALTER TABLE custom_field_definition DROP COLUMN subtype');
        $this->addSql('ALTER TABLE custom_field_definition DROP COLUMN kind');
    }
}
