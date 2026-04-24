<?php

namespace App\Service;

/**
 * Picks a `personalizedColor` hex value for a user. All palette entries are
 * dark enough to render white text on top with WCAG AA contrast (>= 4.5:1);
 * the unit test exercises this invariant.
 */
final class AvatarColorService
{
    /**
     * Sixteen Tailwind-700 shades, all verified to exceed 4.5:1 contrast
     * against white. Adding a color? Add it here and re-run the test.
     */
    public const PALETTE = [
        '#334155', // slate-700
        '#b91c1c', // red-700
        '#c2410c', // orange-700
        '#b45309', // amber-700
        '#854d0e', // yellow-800
        '#4d7c0f', // lime-700
        '#15803d', // green-700
        '#047857', // emerald-700
        '#0f766e', // teal-700
        '#0e7490', // cyan-700
        '#0369a1', // sky-700
        '#1d4ed8', // blue-700
        '#4338ca', // indigo-700
        '#6d28d9', // violet-700
        '#7e22ce', // purple-700
        '#be185d', // pink-700
    ];

    public function pick(): string
    {
        return self::PALETTE[random_int(0, count(self::PALETTE) - 1)];
    }
}
