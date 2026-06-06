<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\QuietHours;
use PHPUnit\Framework\TestCase;

class QuietHoursTest extends TestCase
{
    /**
     * @param array<string, mixed> $quietHours
     */
    private function userWith(array $quietHours, string $timezone = 'UTC'): User
    {
        $user = new User();
        $user->setPreferences([
            'timezone' => $timezone,
            'quietHours' => $quietHours,
        ]);
        return $user;
    }

    private function moment(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }

    public function testDisabledIsNeverQuiet(): void
    {
        $qh = new QuietHours();
        $user = $this->userWith(['enabled' => false, 'start' => '22:00', 'end' => '07:00']);
        $this->assertFalse($qh->isQuiet($user, $this->moment('2026-01-01T23:00:00+00:00')));
    }

    public function testSameDayWindow(): void
    {
        $qh = new QuietHours();
        $user = $this->userWith(['enabled' => true, 'start' => '09:00', 'end' => '17:00']);
        $this->assertTrue($qh->isQuiet($user, $this->moment('2026-01-01T12:00:00+00:00')));
        $this->assertFalse($qh->isQuiet($user, $this->moment('2026-01-01T18:00:00+00:00')));
        $this->assertFalse($qh->isQuiet($user, $this->moment('2026-01-01T08:59:00+00:00')));
    }

    public function testOvernightWrap(): void
    {
        $qh = new QuietHours();
        $user = $this->userWith(['enabled' => true, 'start' => '22:00', 'end' => '07:00']);
        $this->assertTrue($qh->isQuiet($user, $this->moment('2026-01-01T23:00:00+00:00')));
        $this->assertTrue($qh->isQuiet($user, $this->moment('2026-01-01T06:00:00+00:00')));
        $this->assertFalse($qh->isQuiet($user, $this->moment('2026-01-01T12:00:00+00:00')));
    }

    public function testHonoursTimezone(): void
    {
        $qh = new QuietHours();
        // 22:00–07:00 in New York (EST = UTC-5 in January).
        $user = $this->userWith(
            ['enabled' => true, 'start' => '22:00', 'end' => '07:00'],
            'America/New_York',
        );
        // 03:00 UTC == 22:00 EST → quiet.
        $this->assertTrue($qh->isQuiet($user, $this->moment('2026-01-01T03:00:00+00:00')));
        // 18:00 UTC == 13:00 EST → not quiet.
        $this->assertFalse($qh->isQuiet($user, $this->moment('2026-01-01T18:00:00+00:00')));
    }
}
