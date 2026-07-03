<?php

namespace App\Tests\Calendar;

use App\Calendar\RecurrenceRuleMapper;
use PHPUnit\Framework\TestCase;

/**
 * Aura recurrenceRule → provider recurrence mapping (#582 Phase D4).
 */
class RecurrenceRuleMapperTest extends TestCase
{
    private RecurrenceRuleMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new RecurrenceRuleMapper();
    }

    public function testWeeklyByDayGoogle(): void
    {
        $lines = $this->mapper->googleRecurrence(
            ['frequency' => 'weekly', 'interval' => 2, 'byDay' => ['MO', 'WE']],
            new \DateTimeImmutable('2026-08-17'),
            [],
        );

        $this->assertSame(['RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO,WE'], $lines);
    }

    public function testMonthlyByDayGoogleUsesAnchor(): void
    {
        $lines = $this->mapper->googleRecurrence(
            ['frequency' => 'monthly', 'interval' => 1, 'monthlyMode' => 'day'],
            new \DateTimeImmutable('2026-08-15'),
            [],
        );

        $this->assertSame(['RRULE:FREQ=MONTHLY;BYMONTHDAY=15'], $lines);
    }

    public function testMonthlyWeekdayGoogleUsesSetPos(): void
    {
        $lines = $this->mapper->googleRecurrence(
            ['frequency' => 'monthly', 'interval' => 1, 'monthlyMode' => 'weekday', 'byDay' => ['MO'], 'bySetPos' => 2],
            new \DateTimeImmutable('2026-08-10'),
            [],
        );

        $this->assertSame(['RRULE:FREQ=MONTHLY;BYDAY=MO;BYSETPOS=2'], $lines);
    }

    public function testCountAndExdatesGoogle(): void
    {
        $lines = $this->mapper->googleRecurrence(
            ['frequency' => 'daily', 'interval' => 1, 'ends' => ['type' => 'count', 'count' => 5]],
            new \DateTimeImmutable('2026-08-15'),
            [new \DateTimeImmutable('2026-08-16'), new \DateTimeImmutable('2026-08-18')],
        );

        $this->assertSame([
            'RRULE:FREQ=DAILY;COUNT=5',
            'EXDATE;VALUE=DATE:20260816,20260818',
        ], $lines);
    }

    public function testUntilGoogle(): void
    {
        $lines = $this->mapper->googleRecurrence(
            ['frequency' => 'weekly', 'interval' => 1, 'byDay' => ['FR'], 'ends' => ['type' => 'until', 'until' => '2026-12-31']],
            new \DateTimeImmutable('2026-08-14'),
            [],
        );

        $this->assertSame(['RRULE:FREQ=WEEKLY;BYDAY=FR;UNTIL=20261231T000000Z'], $lines);
    }

    public function testWeeklyMicrosoft(): void
    {
        $result = $this->mapper->microsoftPattern(
            ['frequency' => 'weekly', 'interval' => 1, 'byDay' => ['MO', 'WE']],
            new \DateTimeImmutable('2026-08-17'),
        );

        $pattern = $result['pattern'];
        $range = $result['range'];
        $this->assertIsArray($pattern);
        $this->assertIsArray($range);
        $this->assertSame('weekly', $pattern['type']);
        $this->assertSame(['monday', 'wednesday'], $pattern['daysOfWeek']);
        $this->assertSame('noEnd', $range['type']);
        $this->assertSame('2026-08-17', $range['startDate']);
    }

    public function testRelativeMonthlyMicrosoft(): void
    {
        $result = $this->mapper->microsoftPattern(
            ['frequency' => 'monthly', 'interval' => 1, 'monthlyMode' => 'weekday', 'byDay' => ['MO'], 'bySetPos' => -1],
            new \DateTimeImmutable('2026-08-31'),
        );

        $pattern = $result['pattern'];
        $this->assertIsArray($pattern);
        $this->assertSame('relativeMonthly', $pattern['type']);
        $this->assertSame('last', $pattern['index']);
        $this->assertSame(['monday'], $pattern['daysOfWeek']);
    }

    public function testNumberedRangeMicrosoft(): void
    {
        $result = $this->mapper->microsoftPattern(
            ['frequency' => 'daily', 'interval' => 1, 'ends' => ['type' => 'count', 'count' => 3]],
            new \DateTimeImmutable('2026-08-15'),
        );

        $pattern = $result['pattern'];
        $range = $result['range'];
        $this->assertIsArray($pattern);
        $this->assertIsArray($range);
        $this->assertSame('daily', $pattern['type']);
        $this->assertSame('numbered', $range['type']);
        $this->assertSame(3, $range['numberOfOccurrences']);
    }
}
