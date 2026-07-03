<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Translates Aura's `recurrenceRule` (#442 shape) into each provider's native
 * recurring-event format (#582 Phase D4): Google's iCal RRULE/EXDATE lines and
 * Microsoft Graph's structured `patternedRecurrence`. Anchor-relative fields
 * (monthly day-of-month, yearly month/day) are read from the task's due date.
 *
 * Aura rule: {frequency: daily|weekly|monthly|yearly, interval, byDay?: [MO..SU],
 * monthlyMode?: day|weekday, bySetPos?: int, ends?: {type: until, until: Y-m-d}
 * | {type: count, count: int}}.
 */
final class RecurrenceRuleMapper
{
    private const RRULE_FREQ = [
        'daily' => 'DAILY',
        'weekly' => 'WEEKLY',
        'monthly' => 'MONTHLY',
        'yearly' => 'YEARLY',
    ];

    private const GRAPH_DAY = [
        'MO' => 'monday', 'TU' => 'tuesday', 'WE' => 'wednesday', 'TH' => 'thursday',
        'FR' => 'friday', 'SA' => 'saturday', 'SU' => 'sunday',
    ];

    private const GRAPH_INDEX = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth', -1 => 'last'];

    /**
     * Google `recurrence` lines: an RRULE plus an optional EXDATE.
     *
     * @param array<array-key, mixed>       $rule
     * @param list<\DateTimeImmutable>      $exceptions
     *
     * @return list<string>
     */
    public function googleRecurrence(array $rule, \DateTimeImmutable $anchor, array $exceptions): array
    {
        $freq = self::RRULE_FREQ[$this->frequency($rule)] ?? 'DAILY';
        $parts = ['FREQ=' . $freq];

        $interval = $this->interval($rule);
        if ($interval > 1) {
            $parts[] = 'INTERVAL=' . $interval;
        }

        $byDay = $this->byDay($rule);
        if ('WEEKLY' === $freq && [] !== $byDay) {
            $parts[] = 'BYDAY=' . implode(',', $byDay);
        }
        if ('MONTHLY' === $freq) {
            if ('weekday' === ($rule['monthlyMode'] ?? null) && [] !== $byDay) {
                $parts[] = 'BYDAY=' . implode(',', $byDay);
                $pos = $this->bySetPos($rule);
                if (null !== $pos) {
                    $parts[] = 'BYSETPOS=' . $pos;
                }
            } else {
                $parts[] = 'BYMONTHDAY=' . $anchor->format('j');
            }
        }

        foreach ($this->ends($rule) as $key => $value) {
            $parts[] = 'UNTIL' === $key
                ? 'UNTIL=' . $value
                : 'COUNT=' . $value;
        }

        $lines = ['RRULE:' . implode(';', $parts)];
        if ([] !== $exceptions) {
            $dates = array_map(static fn (\DateTimeImmutable $d): string => $d->format('Ymd'), $exceptions);
            $lines[] = 'EXDATE;VALUE=DATE:' . implode(',', $dates);
        }

        return $lines;
    }

    /**
     * Microsoft Graph `patternedRecurrence` object.
     *
     * @param array<array-key, mixed> $rule
     *
     * @return array<string, mixed>
     */
    public function microsoftPattern(array $rule, \DateTimeImmutable $anchor): array
    {
        $frequency = $this->frequency($rule);
        $interval = $this->interval($rule);
        $days = array_values(array_filter(array_map(
            static fn (string $d): ?string => self::GRAPH_DAY[$d] ?? null,
            $this->byDay($rule),
        )));

        $pattern = ['interval' => $interval];
        switch ($frequency) {
            case 'weekly':
                $pattern['type'] = 'weekly';
                $pattern['daysOfWeek'] = [] !== $days ? $days : [strtolower($anchor->format('l'))];
                break;
            case 'monthly':
                if ('weekday' === ($rule['monthlyMode'] ?? null)) {
                    $pattern['type'] = 'relativeMonthly';
                    $pattern['daysOfWeek'] = [] !== $days ? $days : [strtolower($anchor->format('l'))];
                    $pos = $this->bySetPos($rule);
                    $pattern['index'] = self::GRAPH_INDEX[$pos] ?? 'first';
                } else {
                    $pattern['type'] = 'absoluteMonthly';
                    $pattern['dayOfMonth'] = (int) $anchor->format('j');
                }
                break;
            case 'yearly':
                $pattern['type'] = 'absoluteYearly';
                $pattern['month'] = (int) $anchor->format('n');
                $pattern['dayOfMonth'] = (int) $anchor->format('j');
                break;
            default:
                $pattern['type'] = 'daily';
        }

        return ['pattern' => $pattern, 'range' => $this->microsoftRange($rule, $anchor)];
    }

    /**
     * @param array<array-key, mixed> $rule
     *
     * @return array<string, mixed>
     */
    private function microsoftRange(array $rule, \DateTimeImmutable $anchor): array
    {
        $range = ['startDate' => $anchor->format('Y-m-d'), 'recurrenceTimeZone' => 'UTC'];
        $ends = $rule['ends'] ?? null;
        if (is_array($ends)) {
            if ('until' === ($ends['type'] ?? null) && is_string($ends['until'] ?? null)) {
                $range['type'] = 'endDate';
                $range['endDate'] = $ends['until'];

                return $range;
            }
            if ('count' === ($ends['type'] ?? null) && is_int($ends['count'] ?? null)) {
                $range['type'] = 'numbered';
                $range['numberOfOccurrences'] = $ends['count'];

                return $range;
            }
        }
        $range['type'] = 'noEnd';

        return $range;
    }

    /**
     * @param array<array-key, mixed> $rule
     *
     * @return array<string, string> UNTIL => value | COUNT => value (0 or 1 entries)
     */
    private function ends(array $rule): array
    {
        $ends = $rule['ends'] ?? null;
        if (!is_array($ends)) {
            return [];
        }
        if ('until' === ($ends['type'] ?? null) && is_string($ends['until'] ?? null)) {
            $until = \DateTimeImmutable::createFromFormat('!Y-m-d', $ends['until']);
            if (false !== $until) {
                return ['UNTIL' => $until->format('Ymd\T000000\Z')];
            }
        }
        if ('count' === ($ends['type'] ?? null) && is_int($ends['count'] ?? null)) {
            return ['COUNT' => (string) $ends['count']];
        }

        return [];
    }

    /** @param array<array-key, mixed> $rule */
    private function frequency(array $rule): string
    {
        $freq = $rule['frequency'] ?? 'daily';

        return is_string($freq) ? $freq : 'daily';
    }

    /** @param array<array-key, mixed> $rule */
    private function interval(array $rule): int
    {
        $interval = $rule['interval'] ?? 1;

        return is_int($interval) && $interval >= 1 ? $interval : 1;
    }

    /**
     * @param array<array-key, mixed> $rule
     *
     * @return list<string>
     */
    private function byDay(array $rule): array
    {
        $byDay = $rule['byDay'] ?? null;
        if (!is_array($byDay)) {
            return [];
        }

        return array_values(array_filter(
            $byDay,
            static fn (mixed $d): bool => is_string($d) && isset(self::GRAPH_DAY[$d]),
        ));
    }

    /** @param array<array-key, mixed> $rule */
    private function bySetPos(array $rule): ?int
    {
        $pos = $rule['bySetPos'] ?? null;

        return is_int($pos) ? $pos : null;
    }
}
