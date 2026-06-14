<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ReminderScheduler;
use PHPUnit\Framework\TestCase;

class ReminderSchedulerTest extends TestCase
{
    private ReminderScheduler $scheduler;

    protected function setUp(): void
    {
        $this->scheduler = new ReminderScheduler();
    }

    public function testRelativeCanonicalKeyAndShape(): void
    {
        $reminder = ['type' => 'relative', 'value' => 15, 'unit' => 'minutes', 'repeat' => false];
        $this->assertTrue($this->scheduler->isValidShape($reminder));
        $this->assertSame('rel:15:minutes', $this->scheduler->canonicalKey($reminder));
        $this->assertTrue($this->scheduler->requiresDueDate($reminder));
    }

    public function testAbsoluteDoesNotRequireDueDate(): void
    {
        $reminder = ['type' => 'absolute', 'at' => '2026-06-01T09:00:00+00:00', 'repeat' => false];
        $this->assertTrue($this->scheduler->isValidShape($reminder));
        $this->assertFalse($this->scheduler->requiresDueDate($reminder));
    }

    public function testInvalidUnitRejected(): void
    {
        $this->assertFalse($this->scheduler->isValidShape(
            ['type' => 'relative', 'value' => 1, 'unit' => 'fortnights'],
        ));
    }

    public function testRelativeFiresOnceWhenWindowOpen(): void
    {
        $due = new \DateTimeImmutable('2026-06-01T09:00:00+00:00');
        $now = new \DateTimeImmutable('2026-06-01T08:45:00+00:00'); // 15m before due
        $reminder = ['type' => 'relative', 'value' => 30, 'unit' => 'minutes', 'repeat' => false];
        // 30m-before window opened at 08:30, now is 08:45 → fires once.
        $fires = $this->scheduler->dueFires($reminder, $due, $now);
        $this->assertCount(1, $fires);
        $this->assertSame('rel:30:minutes', $fires[0]['key']);
    }

    public function testRelativeDoesNotFireBeforeWindow(): void
    {
        $due = new \DateTimeImmutable('2026-06-01T09:00:00+00:00');
        $now = new \DateTimeImmutable('2026-06-01T08:00:00+00:00');
        $reminder = ['type' => 'relative', 'value' => 30, 'unit' => 'minutes', 'repeat' => false];
        // 30m window opens at 08:30; now is 08:00 → no fire yet.
        $this->assertCount(0, $this->scheduler->dueFires($reminder, $due, $now));
    }

    public function testRepeatDailyKeysByDay(): void
    {
        $due = new \DateTimeImmutable('2026-06-01T09:00:00+00:00');
        $reminder = ['type' => 'relative', 'value' => 0, 'unit' => 'minutes', 'repeat' => true];
        // Anchor = due (value 0). Two days later → latest daily fire is the
        // 2026-06-03 occurrence, keyed with that date.
        $now = new \DateTimeImmutable('2026-06-03T10:00:00+00:00');
        $fires = $this->scheduler->dueFires($reminder, $due, $now);
        $this->assertCount(1, $fires);
        $this->assertStringEndsWith(':2026-06-03', $fires[0]['key']);
    }
}
