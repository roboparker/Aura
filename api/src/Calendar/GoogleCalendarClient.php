<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Entity\OAuthConnection;
use App\Service\OAuthTokenService;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Calendar implementation of {@see CalendarClientInterface} (#582).
 * Talks to the Calendar v3 REST API over Symfony HttpClient (no SDK, matching
 * the SSO + Stripe integrations), authenticated with the connection's access
 * token — refreshed transparently by {@see OAuthTokenService}.
 */
final class GoogleCalendarClient implements CalendarClientInterface, CalendarSubscriberInterface
{
    private const BASE = 'https://www.googleapis.com/calendar/v3';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OAuthTokenService $tokens,
        private readonly RecurrenceRuleMapper $recurrence,
    ) {
    }

    public function createEvent(OAuthConnection $connection, string $calendarId, CalendarEvent $event): string
    {
        $body = $this->request(
            $connection,
            'POST',
            '/calendars/' . rawurlencode($calendarId) . '/events',
            $this->payload($event),
        );
        $id = $body['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new CalendarSyncException('Google Calendar returned no event id.');
        }

        return $id;
    }

    public function updateEvent(
        OAuthConnection $connection,
        string $calendarId,
        string $eventId,
        CalendarEvent $event,
    ): void {
        $this->request(
            $connection,
            'PUT',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId),
            $this->payload($event),
        );
    }

    public function deleteEvent(OAuthConnection $connection, string $calendarId, string $eventId): void
    {
        $this->request(
            $connection,
            'DELETE',
            '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId),
            null,
        );
    }

    public function listChanges(OAuthConnection $connection, string $calendarId, ?string $syncToken): CalendarChangePage
    {
        $token = $this->tokens->accessToken($connection);
        if (null === $token) {
            throw new CalendarSyncException('No valid access token for the calendar connection.');
        }

        $url = self::BASE . '/calendars/' . rawurlencode($calendarId) . '/events';
        $changes = [];
        $pageToken = null;
        $nextSyncToken = null;

        do {
            // syncToken can't be combined with most filters, so the initial
            // (no-token) pass carries timeMin to bound history; incremental
            // passes carry only the cursor.
            $query = ['singleEvents' => 'true', 'showDeleted' => 'true', 'maxResults' => 250];
            if (null !== $syncToken) {
                $query['syncToken'] = $syncToken;
            } else {
                $query['timeMin'] = (new \DateTimeImmutable('today'))->format(\DateTimeInterface::RFC3339);
            }
            if (null !== $pageToken) {
                $query['pageToken'] = $pageToken;
            }

            try {
                $response = $this->httpClient->request('GET', $url, ['auth_bearer' => $token, 'query' => $query]);
                $status = $response->getStatusCode();
                if (410 === $status) {
                    return CalendarChangePage::invalidToken();
                }
                if ($status >= 400) {
                    throw new CalendarSyncException(sprintf('Google Calendar API error (%d).', $status));
                }
                $body = $response->toArray();
            } catch (HttpExceptionInterface $e) {
                $code = $e->getResponse()->getStatusCode();
                if (410 === $code) {
                    return CalendarChangePage::invalidToken();
                }
                throw new CalendarSyncException(sprintf('Google Calendar API error (%d).', $code), 0, $e);
            }

            $items = $body['items'] ?? null;
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $change = $this->toChange($item);
                        if (null !== $change) {
                            $changes[] = $change;
                        }
                    }
                }
            }

            $pageToken = is_string($body['nextPageToken'] ?? null) ? $body['nextPageToken'] : null;
            if (is_string($body['nextSyncToken'] ?? null)) {
                $nextSyncToken = $body['nextSyncToken'];
            }
        } while (null !== $pageToken);

        return new CalendarChangePage($changes, $nextSyncToken);
    }

    public function subscribe(
        OAuthConnection $connection,
        string $notificationUrl,
        string $secret,
    ): CalendarSubscriptionResult {
        $channelId = Uuid::v4()->toRfc4122();
        $body = $this->request($connection, 'POST', '/calendars/primary/events/watch', [
            'id' => $channelId,
            'type' => 'web_hook',
            'address' => $notificationUrl,
            'token' => $secret,
        ]);

        $resourceId = is_string($body['resourceId'] ?? null) ? $body['resourceId'] : null;
        // `expiration` is a string of milliseconds since the epoch.
        $expiration = $body['expiration'] ?? null;
        $expiresAt = is_string($expiration) && ctype_digit($expiration)
            ? (new \DateTimeImmutable())->setTimestamp((int) ((int) $expiration / 1000))
            : new \DateTimeImmutable('+7 days');

        return new CalendarSubscriptionResult($channelId, $resourceId, $expiresAt);
    }

    public function unsubscribe(OAuthConnection $connection, string $channelId, ?string $resourceId): void
    {
        $this->request($connection, 'POST', '/channels/stop', [
            'id' => $channelId,
            'resourceId' => $resourceId ?? '',
        ]);
    }

    /**
     * @param array<array-key, mixed> $item
     */
    private function toChange(array $item): ?CalendarChange
    {
        $id = $item['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            return null;
        }
        $updated = $this->parseTimestamp($item['updated'] ?? null);
        if ('cancelled' === ($item['status'] ?? null)) {
            return new CalendarChange($id, true, null, $updated);
        }

        return new CalendarChange($id, false, $this->extractDate($item['start'] ?? null), $updated);
    }

    private function parseTimestamp(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractDate(mixed $start): ?\DateTimeImmutable
    {
        if (!is_array($start)) {
            return null;
        }
        $date = $start['date'] ?? null;
        if (is_string($date) && '' !== $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            return false !== $parsed ? $parsed : null;
        }
        $dateTime = $start['dateTime'] ?? null;
        if (is_string($dateTime) && '' !== $dateTime) {
            try {
                return new \DateTimeImmutable($dateTime);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<array-key, mixed>
     */
    private function request(OAuthConnection $connection, string $method, string $path, ?array $json): array
    {
        $token = $this->tokens->accessToken($connection);
        if (null === $token) {
            throw new CalendarSyncException('No valid access token for the calendar connection.');
        }

        $options = ['auth_bearer' => $token];
        if (null !== $json) {
            $options['json'] = $json;
        }

        try {
            $response = $this->httpClient->request($method, self::BASE . $path, $options);
            $status = $response->getStatusCode();
            // A missing event on update/delete means the user removed it on
            // Google's side — treat as already-gone so the caller can drop the
            // stale link instead of erroring forever.
            if (404 === $status || 410 === $status) {
                throw CalendarSyncException::gone('Calendar event no longer exists on Google.');
            }
            if ($status >= 400) {
                throw new CalendarSyncException(sprintf('Google Calendar API error (%d).', $status));
            }
            // DELETE returns 204 with no body.
            if (204 === $status) {
                return [];
            }

            return $response->toArray();
        } catch (HttpExceptionInterface $e) {
            $status = $e->getResponse()->getStatusCode();
            if (404 === $status || 410 === $status) {
                throw CalendarSyncException::gone('Calendar event no longer exists on Google.');
            }
            throw new CalendarSyncException(sprintf('Google Calendar API error (%d).', $status), 0, $e);
        }
    }

    /**
     * All-day: start.date inclusive, end.date exclusive (next day). Timed: an
     * RFC3339 dateTime (unambiguous offset) plus the owner's IANA timeZone for
     * display; default 1-hour block.
     *
     * @return array<string, mixed>
     */
    private function payload(CalendarEvent $event): array
    {
        if ($event->allDay) {
            $start = $event->date->setTime(0, 0);
            $end = $start->modify('+1 day');
            $startNode = ['date' => $start->format('Y-m-d')];
            $endNode = ['date' => $end->format('Y-m-d')];
        } else {
            $local = $event->localStart();
            $endLocal = $local->modify('+1 hour');
            $startNode = ['dateTime' => $local->format(\DateTimeInterface::RFC3339), 'timeZone' => $event->timeZone];
            $endNode = ['dateTime' => $endLocal->format(\DateTimeInterface::RFC3339), 'timeZone' => $event->timeZone];
        }

        $payload = [
            'summary' => $event->displaySummary(),
            'description' => $event->description ?? '',
            'start' => $startNode,
            'end' => $endNode,
            // Stable client-side marker so we (and users) can tell Aura-managed
            // events apart; harmless extended property.
            'extendedProperties' => ['private' => ['auraManaged' => 'true']],
        ];

        if (null !== $event->recurrenceRule) {
            $payload['recurrence'] = $this->recurrence->googleRecurrence(
                $event->recurrenceRule,
                $event->date,
                $event->exceptions,
            );
        }

        return $payload;
    }
}
