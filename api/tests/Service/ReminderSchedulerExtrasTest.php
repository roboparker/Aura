<?php

namespace App\Tests\Service;

use App\Service\ReminderScheduler;
use PHPUnit\Framework\TestCase;

/**
 * Additional coverage for {@see ReminderScheduler}: descriptions, the absolute
 * fire path, and shape edge cases the core test doesn't touch.
 */
class ReminderSchedulerExtrasTest extends TestCase
{
    private ReminderScheduler $scheduler;

    protected function setUp(): void
    {
        $this->scheduler = new ReminderScheduler();
    }

    public function testDescribeRelative(): void
    {
        $this->assertSame('when due', $this->scheduler->describe(['type' => 'relative', 'value' => 0, 'unit' => 'minutes']));
        $this->assertSame('1 minute before due', $this->scheduler->describe(['type' => 'relative', 'value' => 1, 'unit' => 'minutes']));
        $this->assertSame('3 hours before due', $this->scheduler->describe(['type' => 'relative', 'value' => 3, 'unit' => 'hours']));
    }

    public function testDescribeAbsoluteAndUnknown(): void
    {
        $this->assertStringStartsWith('on ', $this->scheduler->describe(['type' => 'absolute', 'at' => '2026-08-15T09:00:00Z']));
        $this->assertSame('at a set time', $this->scheduler->describe(['type' => 'absolute', 'at' => 'garbage']));
        $this->assertSame('reminder', $this->scheduler->describe(['type' => 'mystery']));
    }

    public function testRequiresDueDate(): void
    {
        $this->assertTrue($this->scheduler->requiresDueDate(['type' => 'relative', 'value' => 5, 'unit' => 'minutes']));
        $this->assertFalse($this->scheduler->requiresDueDate(['type' => 'absolute', 'at' => '2026-08-15T09:00:00Z']));
    }

    public function testAbsoluteFireDueWhenPast(): void
    {
        $now = new \DateTimeImmutable('2026-08-15T10:00:00Z');
        $fires = $this->scheduler->dueFires(['type' => 'absolute', 'at' => '2026-08-15T09:00:00Z'], null, $now);
        $this->assertCount(1, $fires);
        $this->assertStringStartsWith('abs:', $fires[0]['key']);

        // Future absolute → no fire.
        $this->assertSame([], $this->scheduler->dueFires(
            ['type' => 'absolute', 'at' => '2026-08-20T09:00:00Z'],
            null,
            $now,
        ));
    }

    public function testCanonicalKeyUnknown(): void
    {
        $this->assertStringStartsWith('unknown:', $this->scheduler->canonicalKey(['type' => 'nope']));
    }

    public function testShapeEdges(): void
    {
        // repeat present but not a bool → invalid.
        $this->assertFalse($this->scheduler->isValidShape(['type' => 'relative', 'value' => 1, 'unit' => 'minutes', 'repeat' => 'yes']));
        // absolute with a valid timestamp → valid.
        $this->assertTrue($this->scheduler->isValidShape(['type' => 'absolute', 'at' => '2026-08-15T09:00:00Z']));
        // absolute with unparseable time → invalid.
        $this->assertFalse($this->scheduler->isValidShape(['type' => 'absolute', 'at' => 'nope']));
        // negative relative value → invalid.
        $this->assertFalse($this->scheduler->isValidShape(['type' => 'relative', 'value' => -1, 'unit' => 'minutes']));
    }
}
