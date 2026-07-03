<?php

declare(strict_types=1);

namespace App\Calendar;

/**
 * Provider-agnostic description of the calendar event a task maps to (#582).
 *
 * A dated task with no time-of-day is an **all-day** event on its due date
 * (only the Y-m-d matters). A task with a specific due time is a **timed**
 * event: `date` is the exact instant and `timeZone` is the owner's IANA zone,
 * so the client can render the wall-clock in the right zone (#582 Phase D).
 * The client translates this into each provider's wire format.
 */
final class CalendarEvent
{
    /**
     * @param array<array-key, mixed>|null $recurrenceRule Aura recurrence rule (#442) — null for a one-off event
     * @param list<\DateTimeImmutable>     $exceptions     EXDATE days for the recurring series
     */
    public function __construct(
        public readonly string $summary,
        public readonly ?string $description,
        public readonly \DateTimeImmutable $date,
        public readonly bool $completed,
        public readonly bool $allDay = true,
        public readonly string $timeZone = 'UTC',
        public readonly ?array $recurrenceRule = null,
        public readonly array $exceptions = [],
    ) {
    }

    /** The instant expressed in the event's own time zone (for timed events). */
    public function localStart(): \DateTimeImmutable
    {
        try {
            return $this->date->setTimezone(new \DateTimeZone($this->timeZone));
        } catch (\Throwable) {
            return $this->date->setTimezone(new \DateTimeZone('UTC'));
        }
    }

    /** Title as it should appear on the calendar (done tasks get a check). */
    public function displaySummary(): string
    {
        $summary = '' !== $this->summary ? $this->summary : '(untitled task)';

        return $this->completed ? '✓ ' . $summary : $summary;
    }
}
