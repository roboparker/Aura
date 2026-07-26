<?php

declare(strict_types=1);

namespace App\Dashboard\Widget;

use App\Analytics\AnalyticsMetricInterface;
use App\Entity\Space;
use App\Entity\User;

/**
 * One metric as a single headline figure with its change over the period — the
 * KPI tile next to {@see AnalyticsMetricWidget}'s chart.
 *
 * Extends the chart widget rather than copying it: same metric lookup, same
 * config-dependent permission rule (the part that must not drift between the
 * two), different presentation. Only what actually differs is overridden.
 */
final class AnalyticsStatWidget extends AnalyticsMetricWidget
{
    private const RANGES = [3, 6, 12, 24];

    public function type(): string
    {
        return 'analytics.stat';
    }

    public function name(): string
    {
        return 'Metric total';
    }

    public function description(): string
    {
        return 'A single headline number for one metric, with its change over the period.';
    }

    public function defaultSize(): array
    {
        // A number needs far less room than a chart.
        return ['w' => 1, 'h' => 1];
    }

    public function configSchema(): array
    {
        return [
            [
                'key' => 'metric',
                'type' => 'select',
                'label' => 'Metric',
                'options' => array_map(
                    fn (AnalyticsMetricInterface $m) => ['value' => $m->key(), 'label' => $m->label()],
                    $this->registry->all(),
                ),
            ],
            [
                'key' => 'months',
                'type' => 'select',
                'label' => 'Period',
                'options' => array_map(
                    fn (int $m) => ['value' => $m, 'label' => sprintf('Last %d months', $m)],
                    self::RANGES,
                ),
            ],
        ];
    }

    public function validateConfig(array $config): ?string
    {
        if (null === $this->metricFrom($config)) {
            return 'Choose a metric.';
        }

        $months = $config['months'] ?? 12;
        if (!is_int($months) || !in_array($months, self::RANGES, true)) {
            return sprintf('Period must be one of: %s months.', implode(', ', self::RANGES));
        }

        return null;
    }

    public function data(Space $space, array $config, User $user): array
    {
        $metric = $this->metricFrom($config);
        if (null === $metric) {
            return ['metric' => null, 'total' => 0, 'currency' => null, 'change' => null];
        }

        $months = is_int($config['months'] ?? null) && in_array($config['months'], self::RANGES, true)
            ? $config['months']
            : 12;

        $to = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $from = $to->modify(sprintf('-%d months', $months - 1))->modify('first day of this month');

        // Monthly buckets regardless of period: the tile shows a total and a
        // first-to-last change, so finer buckets would only cost query time.
        $series = $metric->series($this->db, $space, $from, $to, 'month');

        $total = 0;
        $currency = null;
        $first = null;
        $last = null;
        foreach ($series as $one) {
            $currency ??= $one['currency'];
            foreach ($one['points'] as $point) {
                $total += $point['value'];
                $first ??= $point['value'];
                $last = $point['value'];
            }
        }

        // Null rather than infinity when there's no baseline to compare against
        // — "up ∞%" from zero is noise, not information.
        $change = null;
        if (null !== $first && null !== $last && 0 !== $first) {
            $change = (($last - $first) / abs($first)) * 100;
        }

        return [
            'metric' => ['key' => $metric->key(), 'label' => $metric->label(), 'unit' => $metric->unit()],
            'total' => $total,
            'currency' => $currency,
            'change' => $change,
            'months' => $months,
        ];
    }
}
