<?php

declare(strict_types=1);

namespace App\Dashboard\Widget;

use App\Analytics\AnalyticsMetricInterface;
use App\Analytics\AnalyticsQuery;
use App\Analytics\AnalyticsRegistry;
use App\Dashboard\WidgetDefinitionInterface;
use App\Entity\Space;
use App\Entity\User;
use App\Security\Permission\SpacePermission;
use Doctrine\DBAL\Connection;

/**
 * One analytics metric as a chart — the dashboard face of the metrics behind
 * `/reports` (#756).
 *
 * Its data source is the shared {@see AnalyticsRegistry}, so every metric that
 * exists is chartable here the day it's added, and a new metric needs no widget
 * of its own. That's the same composition the widget system itself is for: two
 * registries meeting rather than one reimplementing the other.
 *
 * **Its permission depends on its config**, which is why the interface takes
 * one: pointed at tracked hours this is a `time_entries` widget, pointed at
 * revenue it's an admin-reserved `invoices` widget. A single fixed category
 * would either leak the money or hide the hours.
 */
class AnalyticsMetricWidget implements WidgetDefinitionInterface
{
    /** Window presets, in months back from today. */
    private const RANGES = [3, 6, 12, 24];

    public function __construct(
        protected AnalyticsRegistry $registry,
        protected Connection $db,
    ) {
    }

    public function type(): string
    {
        return 'analytics.metric';
    }

    public function name(): string
    {
        return 'Metric chart';
    }

    public function description(): string
    {
        return 'A business metric over time — revenue, collections, tracked hours, or unbilled work.';
    }

    public function group(): string
    {
        return 'Analytics';
    }

    public function defaultSize(): array
    {
        return ['w' => 2, 'h' => 2];
    }

    public function permissionCategory(array $config = []): ?string
    {
        $metric = $this->metricFrom($config);

        // Empty config = a catalog probe, where no metric is chosen yet. The
        // instance path re-checks once one is, and only admins (who can read
        // everything in their space) ever add widgets.
        if (null === $metric) {
            return array_key_exists('metric', $config)
                // A metric was named but isn't registered — fail closed on the
                // stricter category rather than falling through to "anyone".
                ? SpacePermission::INVOICES
                : null;
        }

        return $metric->permissionCategory();
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
                'key' => 'interval',
                'type' => 'select',
                'label' => 'Group by',
                'options' => [
                    ['value' => 'day', 'label' => 'Daily'],
                    ['value' => 'week', 'label' => 'Weekly'],
                    ['value' => 'month', 'label' => 'Monthly'],
                ],
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
            return 'Choose a metric to chart.';
        }

        $interval = $config['interval'] ?? 'month';
        if (!is_string($interval) || !AnalyticsQuery::isValidInterval($interval)) {
            return 'Group by must be day, week, or month.';
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
        // The metric was removed since this widget was configured. An empty
        // payload renders as "nothing to show" — better than a broken card.
        if (null === $metric) {
            return ['metric' => null, 'series' => []];
        }

        $interval = is_string($config['interval'] ?? null) && AnalyticsQuery::isValidInterval($config['interval'])
            ? $config['interval']
            : 'month';
        $months = is_int($config['months'] ?? null) && in_array($config['months'], self::RANGES, true)
            ? $config['months']
            : 12;

        $to = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $from = $to->modify(sprintf('-%d months', $months - 1))->modify('first day of this month');

        return [
            'metric' => ['key' => $metric->key(), 'label' => $metric->label(), 'unit' => $metric->unit()],
            'interval' => $interval,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'series' => $metric->series($this->db, $space, $from, $to, $interval),
        ];
    }

    /** @param array<string, mixed> $config */
    protected function metricFrom(array $config): ?AnalyticsMetricInterface
    {
        $key = $config['metric'] ?? null;

        return is_string($key) ? $this->registry->get($key) : null;
    }
}
