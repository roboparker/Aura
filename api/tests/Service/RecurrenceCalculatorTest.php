<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\RecurrenceCalculator;
use PHPUnit\Framework\TestCase;

class RecurrenceCalculatorTest extends TestCase
{
    private RecurrenceCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new RecurrenceCalculator();
    }

    public function testDailyInterval(): void
    {
        $dates = $this->calc->nextOccurrences(
            new \DateTimeImmutable('2026-06-01T08:00:00+00:00'),
            ['frequency' => 'daily', 'interval' => 2],
            3,
        );
        $this->assertSame(
            ['2026-06-03', '2026-06-05', '2026-06-07'],
            array_map(fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $dates),
        );
    }

    public function testWeeklyWithoutByDayAdvancesWholeWeeks(): void
    {
        $dates = $this->calc->nextOccurrences(
            new \DateTimeImmutable('2026-06-01T08:00:00+00:00'),
            ['frequency' => 'weekly', 'interval' => 2],
            2,
        );
        $this->assertSame(
            ['2026-06-15', '2026-06-29'],
            array_map(fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $dates),
        );
    }

    public function testWeeklyByDayCyclesSelectedWeekdays(): void
    {
        // 2026-06-01 is a Monday. Mon/Wed/Fri → next three are Wed, Fri, Mon.
        $dates = $this->calc->nextOccurrences(
            new \DateTimeImmutable('2026-06-01T08:00:00+00:00'),
            ['frequency' => 'weekly', 'interval' => 1, 'byDay' => ['MO', 'WE', 'FR']],
            3,
        );
        $this->assertSame(
            ['2026-06-03', '2026-06-05', '2026-06-08'],
            array_map(fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $dates),
        );
    }

    public function testMonthlyByDayOfMonth(): void
    {
        $dates = $this->calc->nextOccurrences(
            new \DateTimeImmutable('2026-01-10T08:00:00+00:00'),
            ['frequency' => 'monthly', 'interval' => 1, 'monthlyMode' => 'day'],
            2,
        );
        $this->assertSame(
            ['2026-02-10', '2026-03-10'],
            array_map(fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $dates),
        );
    }

    public function testMonthlyByDayClampsShortMonthsWithoutDrifting(): void
    {
        // "Monthly on the 31st": February clamps to the 28th (2026 is not a
        // leap year), but the series must snap back to the 31st afterwards —
        // it must NOT drift to March 3rd by overflowing off a mutating cursor.
        $dates = $this->calc->nextOccurrences(
            new \DateTimeImmutable('2026-01-31T08:00:00+00:00'),
            ['frequency' => 'monthly', 'interval' => 1, 'monthlyMode' => 'day'],
            4,
        );
        $this->assertSame(
            ['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'],
            array_map(fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $dates),
        );
        // Time-of-day is preserved across the clamp.
        $this->assertSame('08:00:00', $dates[0]->format('H:i:s'));
    }

    public function testMonthlyByDayHitsLeapDay(): void
    {
        // 2024 is a leap year, so "the 31st" clamps to Feb 29.
        $next = $this->calc->nextDueDate(
            new \DateTimeImmutable('2024-01-31T09:30:00+00:00'),
            ['frequency' => 'monthly', 'interval' => 1, 'monthlyMode' => 'day'],
        );
        $this->assertSame('2024-02-29', $next?->format('Y-m-d'));
    }

    public function testMonthlyNthWeekday(): void
    {
        // Second Thursday of each month starting after 2026-04-09 (itself the
        // 2nd Thursday of April 2026).
        $dates = $this->calc->nextOccurrences(
            new \DateTimeImmutable('2026-04-09T09:00:00+00:00'),
            [
                'frequency' => 'monthly',
                'interval' => 1,
                'monthlyMode' => 'weekday',
                'byDay' => ['TH'],
                'bySetPos' => 2,
            ],
            3,
        );
        $formatted = array_map(fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $dates);
        $this->assertSame(['2026-05-14', '2026-06-11', '2026-07-09'], $formatted);
        foreach ($dates as $d) {
            $this->assertSame('Thursday', $d->format('l'));
        }
    }

    public function testUntilBoundStopsGeneration(): void
    {
        $dates = $this->calc->nextOccurrences(
            new \DateTimeImmutable('2026-06-01T08:00:00+00:00'),
            [
                'frequency' => 'daily',
                'interval' => 1,
                'ends' => ['type' => 'until', 'until' => '2026-06-03'],
            ],
            5,
        );
        $this->assertSame(
            ['2026-06-02', '2026-06-03'],
            array_map(fn (\DateTimeImmutable $d) => $d->format('Y-m-d'), $dates),
        );
    }

    public function testYearly(): void
    {
        $this->assertSame(
            '2027-05-10',
            $this->calc->nextDueDate(
                new \DateTimeImmutable('2026-05-10T00:00:00+00:00'),
                ['frequency' => 'yearly', 'interval' => 1],
            )?->format('Y-m-d'),
        );
    }
}
