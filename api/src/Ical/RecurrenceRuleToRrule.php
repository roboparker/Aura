<?php

namespace App\Ical;

/**
 * Maps Aura's recurrenceRule JSON (see {@see \App\Service\RecurrenceCalculator})
 * to a single RFC 5545 RRULE line, or null when the rule can't be expressed as
 * one RRULE and the feed should instead expand occurrences individually.
 *
 * Cleanly-mappable rules:
 *   - daily / weekly / monthly / yearly with an interval
 *   - weekly BYDAY (which weekdays fire)
 *   - monthly "day" mode when the anchor day is <= 28 (see below)
 *   - monthly "weekday" mode -> BYDAY=<setpos><day> (e.g. 2TH, -1FR)
 *   - ends: UNTIL (UTC) / COUNT
 *
 * The one deliberate mismatch is monthly "day" mode on days 29–31: Aura clamps
 * the day into short months (Jan 31 -> Feb 28) whereas RRULE BYMONTHDAY simply
 * skips months without that day. Those return null so the feed expands the real
 * (clamped) dates instead.
 */
final class RecurrenceRuleToRrule
{
    /** ISO weekday number (1=Mon…7=Sun) -> RRULE day token. */
    private const DAY_TOKENS = [
        1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU',
    ];

    private const VALID_TOKENS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    /**
     * @param array<array-key, mixed> $rule
     */
    public function map(\DateTimeInterface $anchor, array $rule, \DateTimeZone $tz): ?string
    {
        $frequency = is_string($rule['frequency'] ?? null) ? $rule['frequency'] : '';
        $interval = max(1, is_int($rule['interval'] ?? null) ? $rule['interval'] : 1);

        $parts = match ($frequency) {
            'daily' => ['FREQ=DAILY'],
            'weekly' => $this->weeklyParts($rule),
            'monthly' => $this->monthlyParts($anchor, $rule),
            'yearly' => ['FREQ=YEARLY'],
            default => null,
        };
        if (null === $parts) {
            return null;
        }

        if ($interval > 1) {
            $parts[] = 'INTERVAL=' . $interval;
        }

        $ends = $this->endsPart($rule, $tz);
        if (false === $ends) {
            return null;
        }
        if (null !== $ends) {
            $parts[] = $ends;
        }

        return 'RRULE:' . implode(';', $parts);
    }

    /**
     * @param array<array-key, mixed> $rule
     *
     * @return list<string>
     */
    private function weeklyParts(array $rule): array
    {
        $parts = ['FREQ=WEEKLY'];
        $tokens = $this->byDayTokens($rule);
        if ([] !== $tokens) {
            $parts[] = 'BYDAY=' . implode(',', $tokens);
        }

        return $parts;
    }

    /**
     * @param array<array-key, mixed> $rule
     *
     * @return list<string>|null null when the day-mode anchor can't be a clean RRULE
     */
    private function monthlyParts(\DateTimeInterface $anchor, array $rule): ?array
    {
        $mode = ($rule['monthlyMode'] ?? 'day') === 'weekday' ? 'weekday' : 'day';

        if ('day' === $mode) {
            $day = (int) $anchor->format('j');
            // Days 29–31 clamp differently than RRULE BYMONTHDAY — expand.
            if ($day > 28) {
                return null;
            }

            return ['FREQ=MONTHLY', 'BYMONTHDAY=' . $day];
        }

        $tokens = $this->byDayTokens($rule);
        // format('N') is always 1..7, so the lookup always resolves.
        $token = $tokens[0] ?? self::DAY_TOKENS[(int) $anchor->format('N')];
        $setPos = is_int($rule['bySetPos'] ?? null) ? $rule['bySetPos'] : 1;
        if (-1 !== $setPos && ($setPos < 1 || $setPos > 5)) {
            return null;
        }

        return ['FREQ=MONTHLY', 'BYDAY=' . $setPos . $token];
    }

    /**
     * The ends clause: a string (`UNTIL=…`/`COUNT=…`), null for "never ends",
     * or false when an `until` is present but unparseable (map fails).
     *
     * @param array<array-key, mixed> $rule
     */
    private function endsPart(array $rule, \DateTimeZone $tz): string|false|null
    {
        $ends = $rule['ends'] ?? null;
        if (!is_array($ends)) {
            return null;
        }
        $type = $ends['type'] ?? null;

        if ('count' === $type) {
            $count = $ends['count'] ?? null;

            return is_int($count) && $count >= 1 ? 'COUNT=' . $count : null;
        }

        if ('until' === $type) {
            $until = $ends['until'] ?? null;
            if (!is_string($until) || '' === $until) {
                return false;
            }
            // Inclusive end-of-day in the user's zone, expressed in UTC — RRULE
            // UNTIL must be UTC when DTSTART is a local (TZID) time.
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $until, $tz);
            if (false === $parsed) {
                return false;
            }
            $utc = $parsed->setTime(23, 59, 59)->setTimezone(new \DateTimeZone('UTC'));

            return 'UNTIL=' . $utc->format('Ymd\THis\Z');
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $rule
     *
     * @return list<string>
     */
    private function byDayTokens(array $rule): array
    {
        $byDay = $rule['byDay'] ?? null;
        if (!is_array($byDay)) {
            return [];
        }
        $out = [];
        foreach ($byDay as $token) {
            if (is_string($token) && in_array($token, self::VALID_TOKENS, true) && !in_array($token, $out, true)) {
                $out[] = $token;
            }
        }

        return $out;
    }
}
