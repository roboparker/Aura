<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * A single change to a calendar event, as reported by an incremental sync
 * (#582 Phase B). `cancelled` means the user deleted it on the provider side;
 * otherwise `date` is the event's (new) all-day date. Provider-agnostic — the
 * client normalises each provider's delta shape into this.
 */
final class CalendarChange
{
    public function __construct(
        public readonly string $eventId,
        public readonly bool $cancelled,
        public readonly ?\DateTimeImmutable $date,
        /** When the provider last modified the event (for conflict resolution). */
        public readonly ?\DateTimeImmutable $updatedAt = null,
    ) {
    }
}
