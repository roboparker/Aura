<?php

namespace App\Ical;

/**
 * Builds a valid RFC 5545 VTIMEZONE component for an IANA zone by emitting its
 * explicit UTC-offset transitions over a bounded window (rather than deriving
 * recurring RRULE-based transition rules, which is fragile). Every DTSTART /
 * DTEND that carries a `TZID` in the feed must have a matching VTIMEZONE, so
 * the feed builder emits exactly one of these for the user's zone.
 *
 * UTC needs no VTIMEZONE (events are emitted with a `Z` suffix instead), so
 * {@see build} returns null for it.
 */
final class VTimezoneBuilder
{
    public function __construct(private readonly IcalWriter $writer)
    {
    }

    /**
     * @return list<string>|null the VTIMEZONE content lines, or null for UTC
     */
    public function build(\DateTimeZone $tz, \DateTimeImmutable $from, \DateTimeImmutable $to): ?array
    {
        if ('UTC' === $tz->getName()) {
            return null;
        }

        $transitions = $tz->getTransitions($from->getTimestamp(), $to->getTimestamp());
        if ([] === $transitions) {
            return null;
        }

        $lines = [
            'BEGIN:VTIMEZONE',
            $this->writer->line('TZID', $tz->getName()),
        ];

        $previousOffset = $transitions[0]['offset'];
        foreach ($transitions as $index => $transition) {
            // First entry is the state at the window start, not a real
            // transition — anchor its "from" offset to itself so the initial
            // STANDARD/DAYLIGHT component is self-consistent.
            $offsetFrom = 0 === $index ? $transition['offset'] : $previousOffset;
            $lines = array_merge($lines, $this->component($transition, $offsetFrom));
            $previousOffset = $transition['offset'];
        }

        $lines[] = 'END:VTIMEZONE';

        return $lines;
    }

    /**
     * @param array{ts: int, offset: int, isdst: bool, abbr: string} $transition
     *
     * @return list<string>
     */
    private function component(array $transition, int $offsetFrom): array
    {
        $type = $transition['isdst'] ? 'DAYLIGHT' : 'STANDARD';
        // The transition's local wall-clock start is the UTC instant shifted
        // by the offset that takes effect at the transition.
        $localStart = (new \DateTimeImmutable('@' . $transition['ts']))
            ->modify(sprintf('%+d seconds', $transition['offset']))
            ->format('Ymd\THis');

        return [
            'BEGIN:' . $type,
            $this->writer->line('DTSTART', $localStart),
            $this->writer->line('TZOFFSETFROM', $this->formatOffset($offsetFrom)),
            $this->writer->line('TZOFFSETTO', $this->formatOffset($transition['offset'])),
            $this->writer->line('TZNAME', $this->writer->escapeText($transition['abbr'])),
            'END:' . $type,
        ];
    }

    /** Format seconds-of-offset as an iCal UTC offset (`+0100`, `-0430`). */
    private function formatOffset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $abs = abs($seconds);
        $hours = intdiv($abs, 3600);
        $minutes = intdiv($abs % 3600, 60);

        return sprintf('%s%02d%02d', $sign, $hours, $minutes);
    }
}
