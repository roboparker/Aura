<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Entity\OAuthConnection;
use App\Service\OAuthTokenService;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Microsoft Graph implementation of {@see CalendarClientInterface} (#582 Phase
 * C). Talks to Graph v1.0 over Symfony HttpClient (no SDK, like the Google
 * client + SSO + Stripe), authenticated with the connection's access token —
 * refreshed transparently by {@see OAuthTokenService}.
 *
 * Events are written to the account's default calendar (`/me/events`). A dated
 * task is an all-day event (`isAllDay=true`, midnight start → next-midnight end,
 * UTC). Pull uses the calendar-view delta query (`/me/calendarView/delta`); the
 * whole `@odata.deltaLink` URL is the opaque cursor we hand back as the "sync
 * token", so the puller persists + replays it exactly like Google's token. The
 * `$calendarId` argument is ignored — Phase C syncs the default calendar only.
 */
final class MicrosoftCalendarClient implements CalendarClientInterface, CalendarSubscriberInterface
{
    private const BASE = 'https://graph.microsoft.com/v1.0';

    /** How far ahead the initial delta window reaches (delta requires a range). */
    private const WINDOW_DAYS = 400;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly OAuthTokenService $tokens,
        private readonly RecurrenceRuleMapper $recurrence,
    ) {
    }

    public function createEvent(OAuthConnection $connection, string $calendarId, CalendarEvent $event): string
    {
        $body = $this->request($connection, 'POST', self::BASE . '/me/events', $this->payload($event));
        $id = $body['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new CalendarSyncException('Microsoft Graph returned no event id.');
        }

        return $id;
    }

    public function updateEvent(
        OAuthConnection $connection,
        string $calendarId,
        string $eventId,
        CalendarEvent $event,
    ): void {
        $this->request($connection, 'PATCH', self::BASE . '/me/events/' . rawurlencode($eventId), $this->payload($event));
    }

    public function deleteEvent(OAuthConnection $connection, string $calendarId, string $eventId): void
    {
        $this->request($connection, 'DELETE', self::BASE . '/me/events/' . rawurlencode($eventId), null);
    }

    public function listChanges(OAuthConnection $connection, string $calendarId, ?string $syncToken): CalendarChangePage
    {
        $token = $this->tokens->accessToken($connection);
        if (null === $token) {
            throw new CalendarSyncException('No valid access token for the calendar connection.');
        }

        // The cursor is the full delta URL from the previous pass. Without one,
        // start a fresh delta bounded to a forward window (delta requires it).
        $url = $syncToken;
        if (null === $url) {
            $start = (new \DateTimeImmutable('today'))->format('Y-m-d\TH:i:s');
            $end = (new \DateTimeImmutable('today'))->modify('+' . self::WINDOW_DAYS . ' days')->format('Y-m-d\TH:i:s');
            $url = self::BASE . '/me/calendarView/delta?startDateTime=' . rawurlencode($start)
                . '&endDateTime=' . rawurlencode($end);
        }

        $changes = [];
        $deltaLink = null;

        while (null !== $url) {
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'auth_bearer' => $token,
                    'headers' => ['Prefer' => 'outlook.timezone="UTC"'],
                ]);
                $status = $response->getStatusCode();
                if (410 === $status) {
                    return CalendarChangePage::invalidToken();
                }
                if ($status >= 400) {
                    throw new CalendarSyncException(sprintf('Microsoft Graph API error (%d).', $status));
                }
                $body = $response->toArray();
            } catch (HttpExceptionInterface $e) {
                $code = $e->getResponse()->getStatusCode();
                if (410 === $code) {
                    return CalendarChangePage::invalidToken();
                }
                throw new CalendarSyncException(sprintf('Microsoft Graph API error (%d).', $code), 0, $e);
            }

            $items = $body['value'] ?? null;
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

            // Page through @odata.nextLink; the final page carries @odata.deltaLink.
            $next = $body['@odata.nextLink'] ?? null;
            $delta = $body['@odata.deltaLink'] ?? null;
            if (is_string($delta)) {
                $deltaLink = $delta;
            }
            $url = is_string($next) ? $next : null;
        }

        return new CalendarChangePage($changes, $deltaLink);
    }

    public function subscribe(
        OAuthConnection $connection,
        string $notificationUrl,
        string $secret,
    ): CalendarSubscriptionResult {
        // Graph caps /me/events subscriptions at ~4230 min; stay comfortably under.
        $expiration = (new \DateTimeImmutable('+2 days'))->format(\DateTimeInterface::ATOM);
        $body = $this->request($connection, 'POST', self::BASE . '/subscriptions', [
            'changeType' => 'created,updated,deleted',
            'notificationUrl' => $notificationUrl,
            'resource' => '/me/events',
            'expirationDateTime' => $expiration,
            'clientState' => $secret,
        ]);

        $id = $body['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new CalendarSyncException('Microsoft Graph returned no subscription id.');
        }
        $expires = $body['expirationDateTime'] ?? null;
        $expiresAt = $this->parseTimestamp(is_string($expires) ? $expires : null) ?? new \DateTimeImmutable('+2 days');

        return new CalendarSubscriptionResult($id, null, $expiresAt);
    }

    public function unsubscribe(OAuthConnection $connection, string $channelId, ?string $resourceId): void
    {
        $this->request($connection, 'DELETE', self::BASE . '/subscriptions/' . rawurlencode($channelId), null);
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
        $updated = $this->parseTimestamp($item['lastModifiedDateTime'] ?? null);
        if (isset($item['@removed'])) {
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
        $dateTime = $start['dateTime'] ?? null;
        if (!is_string($dateTime) || '' === $dateTime) {
            return null;
        }
        try {
            // We only care about the day; Graph returns local-to-requested-tz
            // (UTC via the Prefer header) datetimes.
            return (new \DateTimeImmutable($dateTime))->setTime(0, 0);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<array-key, mixed>
     */
    private function request(OAuthConnection $connection, string $method, string $url, ?array $json): array
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
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            if (404 === $status || 410 === $status) {
                throw CalendarSyncException::gone('Calendar event no longer exists on Microsoft.');
            }
            if ($status >= 400) {
                throw new CalendarSyncException(sprintf('Microsoft Graph API error (%d).', $status));
            }
            if (204 === $status) {
                return [];
            }

            return $response->toArray();
        } catch (HttpExceptionInterface $e) {
            $code = $e->getResponse()->getStatusCode();
            if (404 === $code || 410 === $code) {
                throw CalendarSyncException::gone('Calendar event no longer exists on Microsoft.');
            }
            throw new CalendarSyncException(sprintf('Microsoft Graph API error (%d).', $code), 0, $e);
        }
    }

    /**
     * All-day: isAllDay=true with midnight start and next-midnight end, UTC.
     * Timed: local wall-clock in the owner's zone (Graph expects the dateTime to
     * be local to `timeZone`, not offset-qualified); default 1-hour block.
     *
     * @return array<string, mixed>
     */
    private function payload(CalendarEvent $event): array
    {
        if ($event->allDay) {
            $start = $event->date->setTime(0, 0);
            $end = $start->modify('+1 day');
            $isAllDay = true;
            $startNode = ['dateTime' => $start->format('Y-m-d\T00:00:00'), 'timeZone' => 'UTC'];
            $endNode = ['dateTime' => $end->format('Y-m-d\T00:00:00'), 'timeZone' => 'UTC'];
        } else {
            $local = $event->localStart();
            $endLocal = $local->modify('+1 hour');
            $isAllDay = false;
            $startNode = ['dateTime' => $local->format('Y-m-d\TH:i:s'), 'timeZone' => $event->timeZone];
            $endNode = ['dateTime' => $endLocal->format('Y-m-d\TH:i:s'), 'timeZone' => $event->timeZone];
        }

        $payload = [
            'subject' => $event->displaySummary(),
            'body' => ['contentType' => 'text', 'content' => $event->description ?? ''],
            'isAllDay' => $isAllDay,
            'start' => $startNode,
            'end' => $endNode,
        ];

        if (null !== $event->recurrenceRule) {
            // Graph series can't carry per-instance EXDATEs; exceptions are
            // separate event instances (out of Phase D4 scope).
            $payload['recurrence'] = $this->recurrence->microsoftPattern($event->recurrenceRule, $event->date);
        }

        return $payload;
    }
}
