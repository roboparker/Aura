<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Entity\OAuthConnection;

/**
 * Pushes calendar events to a provider on a connected account's behalf (#582).
 * Implementations resolve a valid access token from the connection themselves.
 * Phase A has a single Google implementation; Microsoft Graph is a later slice.
 */
interface CalendarClientInterface
{
    /**
     * Create the event and return the provider's event id.
     *
     * @throws CalendarSyncException when the provider call fails or no token
     */
    public function createEvent(OAuthConnection $connection, string $calendarId, CalendarEvent $event): string;

    /** @throws CalendarSyncException when the provider call fails or no token */
    public function updateEvent(
        OAuthConnection $connection,
        string $calendarId,
        string $eventId,
        CalendarEvent $event,
    ): void;

    /** @throws CalendarSyncException when the provider call fails or no token */
    public function deleteEvent(OAuthConnection $connection, string $calendarId, string $eventId): void;

    /**
     * Fetch changes since the given cursor (#582 Phase B). A null cursor starts
     * a fresh sync bounded to future events; the returned page carries the next
     * cursor to persist. Only the raw provider deltas are returned — the caller
     * decides which map to Aura tasks.
     *
     * @throws CalendarSyncException when the provider call fails or no token
     */
    public function listChanges(OAuthConnection $connection, string $calendarId, ?string $syncToken): CalendarChangePage;
}
