<?php

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Calendar\CalendarChange;
use App\Calendar\CalendarChangePage;
use App\Calendar\CalendarClientInterface;
use App\Calendar\CalendarEvent;
use App\Calendar\CalendarSubscriberInterface;
use App\Calendar\CalendarSubscriptionResult;
use App\Calendar\CalendarSyncException;
use App\Entity\OAuthConnection;

/**
 * In-memory {@see CalendarClientInterface} for the test suite (#582) — records
 * pushes and returns synthetic event ids instead of calling Google. Aliased in
 * over {@see \App\Calendar\GoogleCalendarClient} in when@test so the sync
 * handler runs end-to-end without a network. Public so tests can inspect it.
 */
final class InMemoryCalendarClient implements CalendarClientInterface, CalendarSubscriberInterface
{
    /** @var array<string, CalendarEvent> stored events by id */
    public array $events = [];

    /** @var list<array{op: string, provider: string, calendarId: string, eventId: string, summary: string}> */
    public array $calls = [];

    private int $seq = 0;

    /** When set, the next call throws this (to exercise failure handling). */
    public ?CalendarSyncException $failNext = null;

    /** @var list<CalendarChange> changes the next listChanges() will report */
    public array $pendingChanges = [];

    /** Sync cursor returned by listChanges(). */
    public ?string $nextSyncToken = 'sync-1';

    /** When true, the next listChanges() with a non-null cursor reports stale. */
    public bool $reportTokenInvalid = false;

    /** @var list<array{op: string, provider: string, channelId: string}> */
    public array $subscriptions = [];

    private int $subSeq = 0;

    public function createEvent(OAuthConnection $connection, string $calendarId, CalendarEvent $event): string
    {
        $this->maybeFail();
        $id = 'evt-' . (++$this->seq);
        $this->events[$id] = $event;
        $this->log('create', $connection->getProvider(), $calendarId, $id, $event);

        return $id;
    }

    public function updateEvent(
        OAuthConnection $connection,
        string $calendarId,
        string $eventId,
        CalendarEvent $event,
    ): void {
        $this->maybeFail();
        $this->events[$eventId] = $event;
        $this->log('update', $connection->getProvider(), $calendarId, $eventId, $event);
    }

    public function deleteEvent(OAuthConnection $connection, string $calendarId, string $eventId): void
    {
        $this->maybeFail();
        unset($this->events[$eventId]);
        $this->calls[] = [
            'op' => 'delete',
            'provider' => $connection->getProvider(),
            'calendarId' => $calendarId,
            'eventId' => $eventId,
            'summary' => '',
        ];
    }

    public function listChanges(OAuthConnection $connection, string $calendarId, ?string $syncToken): CalendarChangePage
    {
        if ($this->reportTokenInvalid && null !== $syncToken) {
            $this->reportTokenInvalid = false;

            return CalendarChangePage::invalidToken();
        }
        $changes = $this->pendingChanges;
        $this->pendingChanges = [];

        return new CalendarChangePage($changes, $this->nextSyncToken);
    }

    public function subscribe(
        OAuthConnection $connection,
        string $notificationUrl,
        string $secret,
    ): CalendarSubscriptionResult {
        $this->maybeFail();
        $channelId = 'chan-' . (++$this->subSeq);
        $this->subscriptions[] = ['op' => 'subscribe', 'provider' => $connection->getProvider(), 'channelId' => $channelId];

        return new CalendarSubscriptionResult($channelId, 'res-' . $channelId, new \DateTimeImmutable('+2 days'));
    }

    public function unsubscribe(OAuthConnection $connection, string $channelId, ?string $resourceId): void
    {
        $this->subscriptions[] = ['op' => 'unsubscribe', 'provider' => $connection->getProvider(), 'channelId' => $channelId];
    }

    public function reset(): void
    {
        $this->events = [];
        $this->calls = [];
        $this->seq = 0;
        $this->failNext = null;
        $this->pendingChanges = [];
        $this->nextSyncToken = 'sync-1';
        $this->reportTokenInvalid = false;
        $this->subscriptions = [];
        $this->subSeq = 0;
    }

    private function maybeFail(): void
    {
        if (null !== $this->failNext) {
            $e = $this->failNext;
            $this->failNext = null;
            throw $e;
        }
    }

    private function log(string $op, string $provider, string $calendarId, string $eventId, CalendarEvent $event): void
    {
        $this->calls[] = [
            'op' => $op,
            'provider' => $provider,
            'calendarId' => $calendarId,
            'eventId' => $eventId,
            'summary' => $event->displaySummary(),
        ];
    }
}
