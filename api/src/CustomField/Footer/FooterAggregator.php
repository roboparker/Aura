<?php

declare(strict_types=1);

namespace App\CustomField\Footer;

use App\CustomField\CustomFieldKind;
use App\CustomField\CustomFieldTypeRegistry;
use App\Entity\CustomFieldDefinitionInterface;
use App\Entity\GlobalCustomFieldDefinition;
use Doctrine\DBAL\Connection;

/**
 * Computes per-CFD footer aggregations across a precomputed list of
 * task IDs. The caller scopes the task set (project membership +
 * filter chain) and hands the IDs in; this service runs one
 * aggregation query per footer-configured CFD against that scope.
 *
 * Aggregation per kind:
 *
 *   - numeric.int  / .float — `value` is a JSON scalar; cast text -> numeric.
 *   - numeric.money         — `value->>amount` is the JSON-extracted int
 *                             (minor units); rendered back as a {amount,
 *                             currency} pair so the PWA can format.
 *   - date.{date,time,datetime} — `value` is a JSON string; cast text and
 *                             compare lexicographically (the formats are
 *                             ISO-ordered).
 *   - everything            — `count` falls back to a simple
 *                             `COUNT(*) WHERE value IS NOT NULL`.
 *
 * Multi-value fields skip sum/avg/min/max here — aggregating a list of
 * values across a list of tasks is well-defined but rare and the PWA
 * doesn't render it; v1 returns `null` for those rows so the client
 * shows "—".
 */
final class FooterAggregator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CustomFieldTypeRegistry $registry,
    ) {
    }

    /**
     * @param iterable<CustomFieldDefinitionInterface> $definitions
     * @param list<string>                             $taskIds       UUIDs (string) of tasks in scope.
     *
     * @return list<array{
     *     definition: CustomFieldDefinitionInterface,
     *     kind: string,
     *     label: ?string,
     *     value: mixed,
     * }>
     */
    public function aggregate(iterable $definitions, array $taskIds): array
    {
        $rows = [];
        foreach ($definitions as $definition) {
            $footer = $definition->getFooter();
            if (null === $footer) {
                continue;
            }
            $aggKind = $footer['kind'];
            if (!in_array($aggKind, FooterKind::values(), true)) {
                continue;
            }

            $strategy = $this->registry->has($definition->getTypeKey())
                ? $this->registry->get($definition->getTypeKey())
                : null;
            if (null === $strategy || !in_array($aggKind, $strategy->supportedAggregations(), true)) {
                continue;
            }

            $rows[] = [
                'definition' => $definition,
                'kind' => $aggKind,
                'label' => $footer['label'] ?? null,
                'value' => $this->compute($definition, $aggKind, $taskIds),
            ];
        }
        return $rows;
    }

    /**
     * The FK column a value stores this definition in — space-owned fields
     * live in `definition_id`, global fields in `global_definition_id`.
     */
    private function definitionColumn(CustomFieldDefinitionInterface $definition): string
    {
        return $definition instanceof GlobalCustomFieldDefinition ? 'global_definition_id' : 'definition_id';
    }

    /**
     * @param list<string> $taskIds
     */
    private function compute(CustomFieldDefinitionInterface $definition, string $aggKind, array $taskIds): mixed
    {
        // No tasks in scope — every aggregate is either null or 0.
        if ([] === $taskIds) {
            return FooterKind::COUNT->value === $aggKind ? 0 : null;
        }

        if (FooterKind::COUNT->value === $aggKind) {
            return $this->countNonNull($this->definitionColumn($definition), $definition->getId()?->toRfc4122(), $taskIds);
        }

        $kind = $definition->getKind();
        $config = $definition->getConfig();
        $isMulti = true === ($config['multi'] ?? false);

        // Multi-value sum/avg/min/max would need a JSON array unroll —
        // out of scope for v1, fall back to a `null` rendering.
        if ($isMulti) {
            return null;
        }

        if (CustomFieldKind::NUMERIC->value === $kind) {
            return $this->aggregateNumeric($definition, $aggKind, $taskIds);
        }
        if (CustomFieldKind::DATE->value === $kind) {
            return $this->aggregateDate($definition, $aggKind, $taskIds);
        }

        // Strategy advertised an aggregation the aggregator doesn't
        // know how to compute (shouldn't happen given the
        // supportedAggregations() guard above). Bail with null.
        return null;
    }

    /**
     * @param list<string> $taskIds
     */
    private function countNonNull(string $column, ?string $definitionId, array $taskIds): int
    {
        if (null === $definitionId) {
            return 0;
        }
        [$placeholders, $params] = $this->bindUuids($taskIds);
        $sql = sprintf(
            'SELECT COUNT(*)
             FROM custom_field_value
             WHERE %s = ?
               AND task_id IN (%s)
               AND value IS NOT NULL',
            $column,
            $placeholders,
        );
        $stmt = $this->connection->executeQuery(
            $sql,
            array_merge([$definitionId], $params),
        );
        $count = $stmt->fetchOne();
        if (is_int($count)) {
            return $count;
        }
        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @param list<string> $taskIds
     */
    private function aggregateNumeric(CustomFieldDefinitionInterface $definition, string $aggKind, array $taskIds): mixed
    {
        $definitionId = $definition->getId()?->toRfc4122();
        if (null === $definitionId) {
            return null;
        }
        $column = $this->definitionColumn($definition);
        [$placeholders, $params] = $this->bindUuids($taskIds);

        $isMoney = 'money' === $definition->getSubtype();
        // For money, the JSON value is `{amount, currency}`; for int/float
        // it's a bare numeric JSON token. Both extract via `#>>` (JSON
        // path text) to keep the cast IMMUTABLE-safe in case we ever push
        // this into a generated column.
        $valueExpr = $isMoney
            ? "(value #>> '{amount}')::numeric"
            : "(value #>> '{}')::numeric";

        $aggSql = match ($aggKind) {
            FooterKind::SUM->value => 'SUM',
            FooterKind::AVG->value => 'AVG',
            FooterKind::MIN->value => 'MIN',
            FooterKind::MAX->value => 'MAX',
            default => null,
        };
        if (null === $aggSql) {
            return null;
        }

        $sql = sprintf(
            "SELECT %s(%s)
             FROM custom_field_value
             WHERE %s = ?
               AND task_id IN (%s)
               AND value IS NOT NULL",
            $aggSql,
            $valueExpr,
            $column,
            $placeholders,
        );
        $raw = $this->connection->executeQuery(
            $sql,
            array_merge([$definitionId], $params),
        )->fetchOne();

        if (null === $raw || false === $raw || !is_numeric($raw)) {
            return null;
        }

        // Cast back to the strategy's preferred shape: int/money are
        // integers (money holds minor units), float is float, avg of
        // ints is float (Postgres returns numeric -> string -> we cast).
        $numeric = (float) $raw;
        if ('int' === $definition->getSubtype() && FooterKind::AVG->value !== $aggKind) {
            return (int) $numeric;
        }
        if ($isMoney) {
            $amount = FooterKind::AVG->value === $aggKind ? (int) round($numeric) : (int) $numeric;
            $rawCurrency = $definition->getConfig()['currency'] ?? null;
            $currency = is_string($rawCurrency) ? strtoupper($rawCurrency) : null;
            return ['amount' => $amount, 'currency' => $currency];
        }
        return $numeric;
    }

    /**
     * @param list<string> $taskIds
     */
    private function aggregateDate(CustomFieldDefinitionInterface $definition, string $aggKind, array $taskIds): mixed
    {
        $definitionId = $definition->getId()?->toRfc4122();
        if (null === $definitionId) {
            return null;
        }
        if (!in_array($aggKind, [FooterKind::MIN->value, FooterKind::MAX->value], true)) {
            return null;
        }
        $column = $this->definitionColumn($definition);
        [$placeholders, $params] = $this->bindUuids($taskIds);

        $aggSql = FooterKind::MIN->value === $aggKind ? 'MIN' : 'MAX';
        // Date subtypes store ISO strings; their lex ordering matches
        // their chronological ordering, so a string MIN/MAX is
        // semantically correct without parsing.
        $sql = sprintf(
            "SELECT %s(value #>> '{}')
             FROM custom_field_value
             WHERE %s = ?
               AND task_id IN (%s)
               AND value IS NOT NULL",
            $aggSql,
            $column,
            $placeholders,
        );
        $raw = $this->connection->executeQuery(
            $sql,
            array_merge([$definitionId], $params),
        )->fetchOne();
        return is_string($raw) && '' !== $raw ? $raw : null;
    }

    /**
     * @param list<string> $taskIds
     * @return array{0: string, 1: list<string>} `?, ?, ?` placeholder string + the params (UUIDs).
     */
    private function bindUuids(array $taskIds): array
    {
        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        return [$placeholders, $taskIds];
    }
}
