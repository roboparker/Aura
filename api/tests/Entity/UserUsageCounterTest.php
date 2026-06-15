<?php

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\UserUsageCounter;
use PHPUnit\Framework\TestCase;

class UserUsageCounterTest extends TestCase
{
    public function testDefaultsToTodayUtcWithZeroCount(): void
    {
        $counter = new UserUsageCounter();

        $this->assertNull($counter->getId());
        $this->assertSame(0, $counter->getCount());
        $this->assertSame('', $counter->getMetric());
        // Constructor pins the bucket to "today" in UTC.
        $this->assertSame(
            (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->format('Y-m-d'),
            $counter->getDay()->format('Y-m-d'),
        );
    }

    public function testAccessorsRoundTrip(): void
    {
        $user = new User();
        $user->setEmail('counter@example.com');

        $day = new \DateTimeImmutable('2026-06-10', new \DateTimeZone('UTC'));
        $counter = new UserUsageCounter();
        $counter->setUser($user);
        $counter->setDay($day);
        $counter->setMetric('api_call');
        $counter->setCount(42);

        $this->assertSame($user, $counter->getUser());
        $this->assertSame($day, $counter->getDay());
        $this->assertSame('api_call', $counter->getMetric());
        $this->assertSame(42, $counter->getCount());
    }

    public function testSettersAreFluent(): void
    {
        $counter = new UserUsageCounter();

        $this->assertSame($counter, $counter->setMetric('mcp_call'));
        $this->assertSame($counter, $counter->setCount(1));
        $this->assertSame($counter, $counter->setDay(new \DateTimeImmutable('2026-01-01')));
    }
}
