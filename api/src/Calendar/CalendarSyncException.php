<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Raised when a calendar push can't complete (no valid token, provider error).
 * The sync handler catches it: a failed push is logged, not fatal — the task
 * write itself has already succeeded on the request path.
 */
final class CalendarSyncException extends \RuntimeException
{
    /** The provider event no longer exists on its end (treat as already gone). */
    public bool $eventGone = false;

    public static function gone(string $message): self
    {
        $e = new self($message);
        $e->eventGone = true;

        return $e;
    }
}
