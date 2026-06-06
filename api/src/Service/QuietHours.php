<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

/**
 * Resolves whether a user's "quiet hours" window is currently active, in the
 * user's own timezone. During quiet hours the {@see NotificationDispatcher}
 * suppresses email + push (in-app rows still land). Handles the overnight
 * wrap (e.g. 22:00 → 07:00).
 */
final class QuietHours
{
    public function isQuiet(User $user, ?\DateTimeImmutable $now = null): bool
    {
        $prefs = $user->getPreferences();
        $quiet = $prefs['quietHours'] ?? null;
        if (!is_array($quiet) || true !== ($quiet['enabled'] ?? false)) {
            return false;
        }

        $start = $this->toMinutes($quiet['start'] ?? null);
        $end = $this->toMinutes($quiet['end'] ?? null);
        if (null === $start || null === $end || $start === $end) {
            return false;
        }

        $tz = is_string($prefs['timezone'] ?? null) ? $prefs['timezone'] : 'UTC';
        try {
            $zone = new \DateTimeZone($tz);
        } catch (\Exception) {
            $zone = new \DateTimeZone('UTC');
        }

        $local = ($now ?? new \DateTimeImmutable('now'))->setTimezone($zone);
        $current = ((int) $local->format('G')) * 60 + (int) $local->format('i');

        // Same-day window vs overnight wrap.
        if ($start < $end) {
            return $current >= $start && $current < $end;
        }
        return $current >= $start || $current < $end;
    }

    private function toMinutes(mixed $time): ?int
    {
        if (!is_string($time) || 1 !== preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $time, $m)) {
            return null;
        }
        return ((int) $m[1]) * 60 + (int) $m[2];
    }
}
