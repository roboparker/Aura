<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * Resolves a {@see ChatProviderInterface} by name (#827), mirroring
 * {@see \App\Calendar\CalendarClientRegistry}.
 *
 * Wired explicitly in services.yaml (openai → {@see OpenAiChatProvider}; the
 * in-memory double in `when@test`) so callers never name an implementation.
 * Adding a second model is one class plus one line there.
 */
final class ChatProviderRegistry
{
    public const DEFAULT_PROVIDER = 'openai';

    /**
     * @param array<string, ChatProviderInterface> $providers keyed by name
     */
    public function __construct(private readonly array $providers)
    {
    }

    public function get(string $name): ?ChatProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * The provider to use when nothing pins one: the default if it is
     * configured, otherwise the first that is.
     *
     * Falling through rather than insisting on the default is what lets an
     * instance run on a second model without also having to hold OpenAI
     * credentials it does not use.
     */
    public function preferred(): ?ChatProviderInterface
    {
        $default = $this->providers[self::DEFAULT_PROVIDER] ?? null;
        if (null !== $default && $default->isConfigured()) {
            return $default;
        }

        foreach ($this->providers as $provider) {
            if ($provider->isConfigured()) {
                return $provider;
            }
        }

        return null;
    }

    /** Whether this instance can talk to any model at all. */
    public function hasConfiguredProvider(): bool
    {
        return null !== $this->preferred();
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->providers);
    }
}
