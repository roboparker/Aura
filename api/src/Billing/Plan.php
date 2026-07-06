<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * The membership tiers (#billing). A space's (later: an organization's)
 * subscription carries one of these; {@see PlanCatalog} maps each to its
 * entitlements. Ordered by `rank()` so "the best plan a user has" is a simple
 * max.
 *
 * `fromStorage()` is the single place that reads the persisted string, so the
 * legacy `'team'` value (the original single paid plan) resolves cleanly to
 * Business and anything unrecognised falls back to Free.
 */
enum Plan: string
{
    case Free = 'free';
    case Pro = 'pro';
    case Business = 'business';
    case Enterprise = 'enterprise';

    public static function fromStorage(?string $value): self
    {
        return match ($value) {
            'pro' => self::Pro,
            // Legacy: 'team' was the original all-features paid plan.
            'business', 'team' => self::Business,
            'enterprise' => self::Enterprise,
            default => self::Free,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Free => 0,
            self::Pro => 1,
            self::Business => 2,
            self::Enterprise => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Free',
            self::Pro => 'Pro',
            self::Business => 'Business',
            self::Enterprise => 'Enterprise',
        };
    }

    public function isPaid(): bool
    {
        return self::Free !== $this;
    }

    /** The higher-ranked of two plans. */
    public function max(self $other): self
    {
        return $other->rank() > $this->rank() ? $other : $this;
    }
}
