<?php

declare(strict_types=1);

namespace App\Dashboard;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every registered {@see WidgetDefinitionInterface}, keyed by its wire type.
 *
 * Collected through the `app.dashboard_widget` tag, so a new widget needs no
 * wiring beyond implementing the interface.
 */
final class WidgetRegistry
{
    /** @var array<string, WidgetDefinitionInterface> */
    private array $widgets = [];

    /**
     * @param iterable<WidgetDefinitionInterface> $widgets
     */
    public function __construct(
        #[AutowireIterator('app.dashboard_widget')]
        iterable $widgets,
    ) {
        foreach ($widgets as $widget) {
            $this->widgets[$widget->type()] = $widget;
        }
    }

    /** @return list<WidgetDefinitionInterface> */
    public function all(): array
    {
        return array_values($this->widgets);
    }

    public function get(string $type): ?WidgetDefinitionInterface
    {
        return $this->widgets[$type] ?? null;
    }

    public function has(string $type): bool
    {
        return isset($this->widgets[$type]);
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->widgets);
    }
}
