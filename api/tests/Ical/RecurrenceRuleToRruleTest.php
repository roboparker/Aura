<?php

namespace App\Tests\Ical;

use App\Ical\RecurrenceRuleToRrule;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the recurrenceRule -> RRULE mapping, including the cases
 * that deliberately return null so the feed falls back to expansion.
 */
class RecurrenceRuleToRruleTest extends TestCase
{
    private RecurrenceRuleToRrule $mapper;
    private \DateTimeImmutable $anchor;

    protected function setUp(): void
    {
        $this->mapper = new RecurrenceRuleToRrule();
        // A Wednesday.
        $this->anchor = new \DateTimeImmutable('2026-07-15T09:00:00+00:00');
    }

    /**
     * @param array<array-key, mixed> $rule
     */
    private function map(array $rule, string $tz = 'UTC'): ?string
    {
        return $this->mapper->map($this->anchor, $rule, new \DateTimeZone($tz));
    }

    public function testDailyWithInterval(): void
    {
        $this->assertSame(
            'RRULE:FREQ=DAILY;INTERVAL=2',
            $this->map(['frequency' => 'daily', 'interval' => 2]),
        );
    }

    public function testDailyIntervalOneOmitsInterval(): void
    {
        $this->assertSame(
            'RRULE:FREQ=DAILY',
            $this->map(['frequency' => 'daily', 'interval' => 1]),
        );
    }

    public function testWeeklyByDay(): void
    {
        $this->assertSame(
            'RRULE:FREQ=WEEKLY;BYDAY=MO,WE',
            $this->map(['frequency' => 'weekly', 'interval' => 1, 'byDay' => ['MO', 'WE']]),
        );
    }

    public function testWeeklyWithCount(): void
    {
        $this->assertSame(
            'RRULE:FREQ=WEEKLY;COUNT=5',
            $this->map([
                'frequency' => 'weekly',
                'interval' => 1,
                'ends' => ['type' => 'count', 'count' => 5],
            ]),
        );
    }

    public function testMonthlyDayModeUnder29(): void
    {
        $anchor = new \DateTimeImmutable('2026-07-15T09:00:00+00:00');
        $this->assertSame(
            'RRULE:FREQ=MONTHLY;BYMONTHDAY=15',
            $this->mapper->map($anchor, ['frequency' => 'monthly', 'interval' => 1], new \DateTimeZone('UTC')),
        );
    }

    public function testMonthlyDayMode31DoesNotMap(): void
    {
        // Aura clamps day 31 into short months; RRULE BYMONTHDAY would skip
        // them. So this must return null and let the feed expand instead.
        $anchor = new \DateTimeImmutable('2026-07-31T09:00:00+00:00');
        $this->assertNull(
            $this->mapper->map($anchor, ['frequency' => 'monthly', 'interval' => 1], new \DateTimeZone('UTC')),
        );
    }

    public function testMonthlyWeekdayNth(): void
    {
        $this->assertSame(
            'RRULE:FREQ=MONTHLY;BYDAY=2TH',
            $this->map([
                'frequency' => 'monthly',
                'interval' => 1,
                'monthlyMode' => 'weekday',
                'byDay' => ['TH'],
                'bySetPos' => 2,
            ]),
        );
    }

    public function testMonthlyWeekdayLast(): void
    {
        $this->assertSame(
            'RRULE:FREQ=MONTHLY;BYDAY=-1FR',
            $this->map([
                'frequency' => 'monthly',
                'interval' => 1,
                'monthlyMode' => 'weekday',
                'byDay' => ['FR'],
                'bySetPos' => -1,
            ]),
        );
    }

    public function testYearly(): void
    {
        $this->assertSame(
            'RRULE:FREQ=YEARLY',
            $this->map(['frequency' => 'yearly', 'interval' => 1]),
        );
    }

    public function testUntilIsConvertedToUtc(): void
    {
        // 2026-12-31 23:59:59 in America/New_York (EST, -05:00) is
        // 2027-01-01 04:59:59 UTC.
        $result = $this->map([
            'frequency' => 'daily',
            'interval' => 1,
            'ends' => ['type' => 'until', 'until' => '2026-12-31'],
        ], 'America/New_York');

        $this->assertSame('RRULE:FREQ=DAILY;UNTIL=20270101T045959Z', $result);
    }

    public function testUnknownFrequencyDoesNotMap(): void
    {
        $this->assertNull($this->map(['frequency' => 'fortnightly', 'interval' => 1]));
    }
}
