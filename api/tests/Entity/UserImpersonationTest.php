<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * User's admin-impersonation consent accessors (category + per-item levels).
 */
class UserImpersonationTest extends TestCase
{
    private function user(): User
    {
        $user = new User();
        $user->setPreferences([
            'impersonationAccess' => ['tasks' => 'view', 'boards' => 'edit', 'bad' => 'nonsense'],
            'impersonationItemAccess' => [
                'task' => ['uuid-1' => 'none', 'uuid-2' => 'edit', 'uuid-3' => 'bogus'],
            ],
        ]);

        return $user;
    }

    public function testCategoryLevel(): void
    {
        $user = $this->user();
        $this->assertSame('view', $user->getImpersonationLevel('tasks'));
        $this->assertSame('edit', $user->getImpersonationLevel('boards'));
        // Invalid stored value → safe default.
        $this->assertSame('none', $user->getImpersonationLevel('bad'));
        // Unset category → default none.
        $this->assertSame('none', $user->getImpersonationLevel('comments'));
    }

    public function testItemLevelOverrideWinsThenFallsBackToCategory(): void
    {
        $user = $this->user();
        $this->assertSame('none', $user->getImpersonationItemLevel('task', 'uuid-1'));
        $this->assertSame('edit', $user->getImpersonationItemLevel('task', 'uuid-2'));
        // Malformed override → ignored, falls back to the 'tasks' category (view).
        $this->assertSame('view', $user->getImpersonationItemLevel('task', 'uuid-3'));
        // No override → category default.
        $this->assertSame('view', $user->getImpersonationItemLevel('task', 'uuid-99'));
    }

    public function testItemPartition(): void
    {
        $partition = $this->user()->impersonationItemPartition('task');
        $this->assertSame(['uuid-1'], $partition['none']);
        $this->assertContains('uuid-2', $partition['visible']);
        $this->assertNotContains('uuid-3', $partition['visible']);
    }

    public function testPartitionEmptyForUnknownType(): void
    {
        $partition = $this->user()->impersonationItemPartition('board');
        $this->assertSame(['none' => [], 'visible' => []], $partition);
    }
}
