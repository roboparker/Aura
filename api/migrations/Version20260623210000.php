<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Intl\Currencies;

/**
 * Repair money custom-field values stored as bare numbers.
 *
 * Before the #533 value-conversion feature, changing a custom field's type
 * left existing values untouched — so a numeric field switched to
 * `numeric.money` kept plain-number values (e.g. `200`) instead of the
 * required `{amount, currency}` shape. The Money validator then rejects
 * *every* PATCH on those tasks, so they can't be edited at all.
 *
 * This converts each bare-number value on a money definition to
 * `{amount: round(n * 10^digits), currency}`, treating the number as a
 * major-unit amount (200 -> $200.00 = 20000 minor units). `digits` comes
 * from the currency's ISO fraction digits (USD 2, JPY 0, …). The
 * `value_search` projection is refreshed to match
 * {@see \App\CustomField\Type\Numeric\MoneyStrategy::searchText()} so FTS
 * stays consistent (the STORED `search_vector` regenerates from it).
 *
 * Idempotent: values already in object shape are skipped, so a re-run is a
 * no-op. Pure data — no schema change.
 */
final class Version20260623210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repair money custom-field values stored as bare numbers into {amount, currency}.';
    }

    public function up(Schema $schema): void
    {
        $definitions = $this->connection->fetchAllAssociative(
            "SELECT id, config FROM custom_field_definition WHERE kind = 'numeric' AND subtype = 'money'",
        );

        foreach ($definitions as $definition) {
            $config = $this->decodeArray($definition['config'] ?? null);
            $currency = is_string($config['currency'] ?? null)
                ? strtoupper($config['currency'])
                : 'USD';
            $digits = Currencies::exists($currency)
                ? Currencies::getFractionDigits($currency)
                : 2;

            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, value FROM custom_field_value WHERE definition_id = :def',
                ['def' => $definition['id']],
            );

            foreach ($rows as $row) {
                $raw = $row['value'];
                $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

                // Already an object, null, or a non-numeric value — leave it.
                $number = null;
                if (is_int($decoded) || is_float($decoded)) {
                    $number = (float) $decoded;
                } elseif (is_string($decoded) && is_numeric($decoded)) {
                    $number = (float) $decoded;
                }
                if (null === $number) {
                    continue;
                }

                $amount = (int) round($number * (10 ** $digits));
                $value = json_encode(
                    ['amount' => $amount, 'currency' => $currency],
                    JSON_THROW_ON_ERROR,
                );

                $this->connection->executeStatement(
                    'UPDATE custom_field_value SET value = :value, value_search = :search WHERE id = :id',
                    [
                        'value' => $value,
                        'search' => $amount . ' ' . $currency,
                        'id' => $row['id'],
                    ],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Irreversible: the original bare numbers can't be recovered with
        // confidence once wrapped, and re-running up() is safe, so leave the
        // repaired values in place.
        $this->throwIrreversibleMigrationException(
            'Money value repair cannot be safely reversed.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArray(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) ? $decoded : [];
    }
}
