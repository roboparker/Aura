<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Queues a one-way push of a task to a connected external calendar (#582).
 *
 * `upsert` loads the task and creates/updates/removes its event to match the
 * current state (title, due date, done-ness). `delete` carries the event id +
 * calendar id in the payload because the {@see \App\Entity\CalendarEventLink}
 * row is CASCADE-deleted with the task, so it can't be read back by the worker.
 */
final class SyncTaskToCalendar
{
    public const UPSERT = 'upsert';
    public const DELETE = 'delete';

    public function __construct(
        public readonly string $action,
        public readonly string $ownerId,
        public readonly string $provider = 'google',
        public readonly ?string $taskId = null,
        public readonly ?string $eventId = null,
        public readonly string $calendarId = 'primary',
    ) {
    }

    public static function upsert(string $taskId, string $ownerId, string $provider = 'google'): self
    {
        return new self(self::UPSERT, $ownerId, $provider, taskId: $taskId);
    }

    public static function delete(
        string $ownerId,
        string $eventId,
        string $calendarId,
        string $provider = 'google',
    ): self {
        return new self(self::DELETE, $ownerId, $provider, eventId: $eventId, calendarId: $calendarId);
    }
}
