<?php

namespace App\Service;

/**
 * Computes the next due dates for a task recurrence rule (#227 tasks
 * redesign). Shared by {@see \App\State\TaskUpdateProcessor} (spawns the
 * single next occurrence when a recurring task is completed) and
 * {@see \App\Controller\RecurrencePreviewController} (renders the "next N
 * occurrences" preview in the editor) so the preview can never disagree
 * with what actually gets created.
 *
 * Rule shape (all keys but frequency/interval are optional, so legacy
 * `{frequency, interval}` rows keep working):
 *
 *   {
 *     "frequency": "daily"|"weekly"|"monthly"|"yearly",
 *     "interval":  int >= 1,
 *     "byDay":     ["MO","WE","FR"],     // weekly: which weekdays fire;
 *                                        // monthly+weekday: the single weekday
 *     "monthlyMode": "day"|"weekday",    // monthly only; default "day"
 *     "bySetPos":  int (1..5 | -1),      // monthly+weekday: which week
 *     "ends": {                          // absent/null => never ends
 *       "type": "until"|"count",
 *       "until": "YYYY-MM-DD",           // type=until
 *       "count": int >= 1                // type=count: total occurrences
 *     }
 *   }
 */
final class RecurrenceCalculator
{
    /** ISO-8601 weekday number (1=Mon … 7=Sun) keyed by RRULE day token. */
    private const WEEKDAY_NUMBERS = [
        'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7,
    ];

    /**
     * Returns the occurrence dates strictly after `$after`, in ascending
     * order, capped at `$limit`. Honours an `until` end bound (inclusive).
     * `count`-based ends are NOT applied here — the caller tracks remaining
     * occurrences via the rule's `ends.count` and stops spawning itself, so
     * the preview can pass the remaining count as `$limit`.
     *
     * @param array<array-key, mixed> $rule
     *
     * @return \DateTimeImmutable[]
     */
    public function nextOccurrences(\DateTimeImmutable $after, array $rule, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }
        $frequency = is_string($rule['frequency'] ?? null) ? $rule['frequency'] : '';
        $interval = max(1, is_int($rule['interval'] ?? null) ? $rule['interval'] : 1);
        $until = $this->parseUntil($rule);

        $out = [];
        $generator = match ($frequency) {
            'daily' => $this->dailyDates($after, $interval),
            'weekly' => $this->weeklyDates($after, $interval, $this->byDayNumbers($rule)),
            'monthly' => $this->monthlyDates($after, $interval, $rule),
            'yearly' => $this->yearlyDates($after, $interval),
            default => [],
        };

        foreach ($generator as $date) {
            if (null !== $until && $date > $until) {
                break;
            }
            $out[] = $date;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * The single next due date after `$current`, or null when the series has
     * ended (past `until`, or generation produced nothing). Count-based
     * limits are the caller's concern.
     *
     * @param array<array-key, mixed> $rule
     */
    public function nextDueDate(\DateTimeImmutable $current, array $rule): ?\DateTimeImmutable
    {
        return $this->nextOccurrences($current, $rule, 1)[0] ?? null;
    }

    /**
     * @return \Generator<int, \DateTimeImmutable>
     */
    private function dailyDates(\DateTimeImmutable $after, int $interval): \Generator
    {
        $cursor = $after;
        // A generous upper bound stops a pathological rule from looping
        // forever; callers always cap with $limit well below this.
        for ($i = 0; $i < 3660; ++$i) {
            $cursor = $cursor->modify(sprintf('+%d days', $interval));
            yield $cursor;
        }
    }

    private function yearlyDates(\DateTimeImmutable $after, int $interval): \Generator
    {
        $cursor = $after;
        for ($i = 0; $i < 200; ++$i) {
            $cursor = $cursor->modify(sprintf('+%d years', $interval));
            yield $cursor;
        }
    }

    /**
     * Weekly recurrence with an optional weekday set. With no `byDay` the
     * rule simply advances whole weeks off the anchor. With a `byDay`, every
     * selected weekday fires within each active week, and (interval-1) weeks
     * are skipped between active weeks (WKST = Monday).
     *
     * @param int[] $byDay ISO weekday numbers (1..7)
     *
     * @return \Generator<int, \DateTimeImmutable>
     */
    private function weeklyDates(\DateTimeImmutable $after, int $interval, array $byDay): \Generator
    {
        if ([] === $byDay) {
            $cursor = $after;
            for ($i = 0; $i < 520; ++$i) {
                $cursor = $cursor->modify(sprintf('+%d weeks', $interval));
                yield $cursor;
            }
            return;
        }

        sort($byDay);
        // Monday of the anchor's week.
        $anchorDow = (int) $after->format('N');
        $weekStart = $after->modify(sprintf('-%d days', $anchorDow - 1))->setTime(
            (int) $after->format('H'),
            (int) $after->format('i'),
            (int) $after->format('s'),
        );

        for ($w = 0; $w < 520; ++$w) {
            $base = $weekStart->modify(sprintf('+%d weeks', $w * $interval));
            foreach ($byDay as $dow) {
                $date = $base->modify(sprintf('+%d days', $dow - 1));
                if ($date > $after) {
                    yield $date;
                }
            }
        }
    }

    /**
     * Monthly recurrence in one of two modes:
     *  - "day": same day-of-month as the anchor, stepping `interval` months
     *    (PHP's calendar-aware modify handles short months).
     *  - "weekday": the Nth weekday of the month (bySetPos + byDay[0]), e.g.
     *    "2nd Thursday"; -1 means the last such weekday.
     *
     * @param array<array-key, mixed> $rule
     *
     * @return \Generator<int, \DateTimeImmutable>
     */
    private function monthlyDates(\DateTimeImmutable $after, int $interval, array $rule): \Generator
    {
        $mode = ($rule['monthlyMode'] ?? 'day') === 'weekday' ? 'weekday' : 'day';

        if ('day' === $mode) {
            $cursor = $after;
            for ($i = 0; $i < 600; ++$i) {
                $cursor = $cursor->modify(sprintf('+%d months', $interval));
                yield $cursor;
            }
            return;
        }

        $byDay = $this->byDayNumbers($rule);
        $weekday = $byDay[0] ?? (int) $after->format('N');
        $setPos = is_int($rule['bySetPos'] ?? null) ? $rule['bySetPos'] : 1;
        $hour = (int) $after->format('H');
        $minute = (int) $after->format('i');
        $second = (int) $after->format('s');

        // Walk month-by-month from the anchor's month; only emit when the
        // computed date is strictly after the anchor.
        $monthCursor = $after->modify('first day of this month')->setTime(0, 0);
        for ($i = 0; $i < 600; ++$i) {
            $date = $this->nthWeekdayOfMonth($monthCursor, $weekday, $setPos);
            if (null !== $date) {
                $date = $date->setTime($hour, $minute, $second);
                if ($date > $after) {
                    yield $date;
                }
            }
            $monthCursor = $monthCursor->modify(sprintf('+%d months', $interval));
        }
    }

    /**
     * The Nth (`$pos`: 1..5, or -1 for last) `$weekday` (ISO 1..7) within the
     * month of `$monthFirstDay`. Returns null when the month has no such
     * occurrence (e.g. a 5th Friday that doesn't exist).
     */
    private function nthWeekdayOfMonth(
        \DateTimeImmutable $monthFirstDay,
        int $weekday,
        int $pos,
    ): ?\DateTimeImmutable {
        $first = $monthFirstDay->modify('first day of this month');
        $daysInMonth = (int) $first->format('t');

        $matches = [];
        for ($day = 1; $day <= $daysInMonth; ++$day) {
            $date = $first->setDate(
                (int) $first->format('Y'),
                (int) $first->format('n'),
                $day,
            );
            if ((int) $date->format('N') === $weekday) {
                $matches[] = $date;
            }
        }
        if ([] === $matches) {
            return null;
        }
        if (-1 === $pos) {
            return $matches[count($matches) - 1];
        }
        $index = $pos - 1;
        return $matches[$index] ?? null;
    }

    /**
     * @param array<array-key, mixed> $rule
     *
     * @return int[]
     */
    private function byDayNumbers(array $rule): array
    {
        $byDay = $rule['byDay'] ?? null;
        if (!is_array($byDay)) {
            return [];
        }
        $out = [];
        foreach ($byDay as $token) {
            if (is_string($token) && isset(self::WEEKDAY_NUMBERS[$token])) {
                $out[] = self::WEEKDAY_NUMBERS[$token];
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param array<array-key, mixed> $rule
     */
    private function parseUntil(array $rule): ?\DateTimeImmutable
    {
        $ends = $rule['ends'] ?? null;
        if (!is_array($ends) || ($ends['type'] ?? null) !== 'until') {
            return null;
        }
        $until = $ends['until'] ?? null;
        if (!is_string($until) || '' === $until) {
            return null;
        }
        try {
            // End-of-day so an occurrence falling on the until date counts.
            return new \DateTimeImmutable($until . ' 23:59:59');
        } catch (\Exception) {
            return null;
        }
    }
}
