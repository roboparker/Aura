<?php

declare(strict_types=1);

namespace App\Analytics;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every registered {@see AnalyticsMetricInterface}, keyed by its wire key.
 *
 * Metrics are collected through the `app.analytics_metric` tag, so a new one
 * needs no wiring beyond implementing the interface — the same auto-registration
 * the MCP tools and custom-field strategies use.
 */
final class AnalyticsRegistry
{
    /** @var array<string, AnalyticsMetricInterface> */
    private array $metrics = [];

    /**
     * @param iterable<AnalyticsMetricInterface> $metrics
     */
    public function __construct(
        #[AutowireIterator('app.analytics_metric')]
        iterable $metrics,
    ) {
        foreach ($metrics as $metric) {
            $this->metrics[$metric->key()] = $metric;
        }
    }

    /** @return list<AnalyticsMetricInterface> */
    public function all(): array
    {
        return array_values($this->metrics);
    }

    public function get(string $key): ?AnalyticsMetricInterface
    {
        return $this->metrics[$key] ?? null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->metrics);
    }
}
