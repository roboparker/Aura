<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * The result of an incremental sync pass (#582 Phase B): the changes since the
 * previous cursor plus the next cursor to persist. `tokenInvalid` signals the
 * provider rejected a stale cursor (e.g. Google 410) so the caller drops it and
 * does a fresh full sync.
 */
final class CalendarChangePage
{
    /**
     * @param list<CalendarChange> $changes
     */
    public function __construct(
        public readonly array $changes,
        public readonly ?string $nextSyncToken,
        public readonly bool $tokenInvalid = false,
    ) {
    }

    public static function invalidToken(): self
    {
        return new self([], null, true);
    }
}
