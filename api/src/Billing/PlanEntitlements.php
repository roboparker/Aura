<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * What a {@see Plan} unlocks: a map of feature flags (bool) and numeric limits
 * (int, or `null` = unlimited). Immutable; produced by {@see PlanCatalog}.
 *
 * `limit()` returns `null` for an unlimited key and `0` for an unknown key
 * (fail-closed). `isUnlimited()` distinguishes the two.
 */
final class PlanEntitlements
{
    /**
     * @param array<string, bool>     $features
     * @param array<string, int|null> $limits    value null = unlimited
     */
    public function __construct(
        public readonly Plan $plan,
        public readonly array $features,
        public readonly array $limits,
    ) {
    }

    public function can(string $feature): bool
    {
        return $this->features[$feature] ?? false;
    }

    /** The numeric limit for a key: null = unlimited, 0 = unknown/none. */
    public function limit(string $key): ?int
    {
        if (!array_key_exists($key, $this->limits)) {
            return 0;
        }

        return $this->limits[$key];
    }

    public function isUnlimited(string $key): bool
    {
        return array_key_exists($key, $this->limits) && null === $this->limits[$key];
    }

    /**
     * Serializable view for the billing API — what the PWA shows on the plan /
     * upgrade UI.
     *
     * @return array{plan: string, features: array<string, bool>, limits: array<string, int|null>}
     */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan->value,
            'features' => $this->features,
            'limits' => $this->limits,
        ];
    }
}
