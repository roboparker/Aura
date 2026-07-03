<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Resolves the {@see CalendarClientInterface} for a provider (#582 Phase C).
 * Lets the sync handler + puller stay provider-agnostic — they ask the registry
 * for a client by the connection's provider and skip providers with no client.
 * Wired explicitly in services.yaml (google → Google, microsoft → Microsoft;
 * both → the in-memory recorder in when@test).
 */
final class CalendarClientRegistry
{
    /**
     * @param array<string, CalendarClientInterface> $clients keyed by provider
     */
    public function __construct(private readonly array $clients)
    {
    }

    public function clientFor(string $provider): ?CalendarClientInterface
    {
        return $this->clients[$provider] ?? null;
    }

    /** @return list<string> providers that can be synced on this instance */
    public function providers(): array
    {
        return array_keys($this->clients);
    }
}
