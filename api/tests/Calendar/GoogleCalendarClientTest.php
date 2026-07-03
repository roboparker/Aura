<?php

namespace App\Tests\Calendar;

use App\Calendar\CalendarEvent;
use App\Calendar\CalendarSyncException;
use App\Calendar\GoogleCalendarClient;
use App\Calendar\RecurrenceRuleMapper;
use App\Entity\OAuthConnection;
use App\Service\OAuthTokenService;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Google Calendar wire format (#582) exercised with a mocked HTTP client — the
 * request bodies + response parsing the InMemory double can't cover.
 */
class GoogleCalendarClientTest extends KernelTestCase
{
    private OAuthTokenService $tokens;
    private RecurrenceRuleMapper $mapper;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->tokens = self::getContainer()->get(OAuthTokenService::class);
        $this->mapper = new RecurrenceRuleMapper();
    }

    private function connection(): OAuthConnection
    {
        $connection = new OAuthConnection('google');
        $this->tokens->store($connection, new AccessToken(['access_token' => 'tok-123', 'expires_in' => 3600]));

        return $connection;
    }

    /** A connection whose token is expired with no refresh token → unusable. */
    private function tokenlessConnection(): OAuthConnection
    {
        $connection = new OAuthConnection('google');
        $this->tokens->store($connection, new AccessToken(['access_token' => 'x', 'expires_in' => -100]));

        return $connection;
    }

    public function testCreateWithoutValidTokenThrows(): void
    {
        [$client] = $this->clientReturning(new MockResponse($this->json(['id' => 'x'])));
        $event = new CalendarEvent('t', null, new \DateTimeImmutable('2026-08-15'), false);

        $this->expectException(CalendarSyncException::class);
        $client->createEvent($this->tokenlessConnection(), 'primary', $event);
    }

    public function testApiErrorThrows(): void
    {
        [$client] = $this->clientReturning(new MockResponse('', ['http_code' => 500]));
        $event = new CalendarEvent('t', null, new \DateTimeImmutable('2026-08-15'), false);

        $this->expectException(CalendarSyncException::class);
        $client->createEvent($this->connection(), 'primary', $event);
    }

    public function testCreateWithoutIdThrows(): void
    {
        [$client] = $this->clientReturning(new MockResponse($this->json(['no_id' => true])));
        $event = new CalendarEvent('t', null, new \DateTimeImmutable('2026-08-15'), false);

        $this->expectException(CalendarSyncException::class);
        $client->createEvent($this->connection(), 'primary', $event);
    }

    public function testListChangesPaginates(): void
    {
        $page1 = new MockResponse($this->json([
            'items' => [['id' => 'a', 'status' => 'confirmed', 'start' => ['date' => '2026-08-20']]],
            'nextPageToken' => 'p2',
        ]));
        $page2 = new MockResponse($this->json([
            'items' => [['id' => 'b', 'status' => 'confirmed', 'start' => ['dateTime' => '2026-08-21T09:00:00Z']]],
            'nextSyncToken' => 'final',
        ]));
        $client = new GoogleCalendarClient(new MockHttpClient([$page1, $page2]), $this->tokens, $this->mapper);

        $page = $client->listChanges($this->connection(), 'primary', null);

        $this->assertSame('final', $page->nextSyncToken);
        $this->assertCount(2, $page->changes);
        $this->assertSame('2026-08-21', $page->changes[1]->date?->format('Y-m-d'));
    }

    /** @return array{0: GoogleCalendarClient, 1: MockResponse} */
    private function clientReturning(MockResponse $response): array
    {
        $mock = new MockHttpClient([$response]);

        return [new GoogleCalendarClient($mock, $this->tokens, $this->mapper), $response];
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): string
    {
        $encoded = json_encode($data);

        return false !== $encoded ? $encoded : '{}';
    }

    /** @return array<array-key, mixed> */
    private function body(MockResponse $response): array
    {
        $body = $response->getRequestOptions()['body'] ?? '{}';
        $decoded = json_decode(is_string($body) ? $body : '{}', true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    private function arrayAt(array $body, string $key): array
    {
        $value = $body[$key] ?? null;
        $this->assertIsArray($value);

        return $value;
    }

    public function testCreateAllDayEvent(): void
    {
        [$client, $response] = $this->clientReturning(new MockResponse($this->json(['id' => 'evt-1'])));
        $event = new CalendarEvent('Do it', 'desc', new \DateTimeImmutable('2026-08-15'), false);

        $id = $client->createEvent($this->connection(), 'primary', $event);

        $this->assertSame('evt-1', $id);
        $this->assertSame('POST', $response->getRequestMethod());
        $this->assertStringContainsString('/calendars/primary/events', $response->getRequestUrl());
        $body = $this->body($response);
        $this->assertSame(['date' => '2026-08-15'], $this->arrayAt($body, 'start'));
        $this->assertSame('Do it', $body['summary'] ?? null);
    }

    public function testCreateTimedEventCarriesTimeZone(): void
    {
        [$client, $response] = $this->clientReturning(new MockResponse($this->json(['id' => 'evt-2'])));
        $event = new CalendarEvent(
            'Standup',
            null,
            new \DateTimeImmutable('2026-08-15T14:30:00+00:00'),
            false,
            false,
            'America/New_York',
        );

        $client->createEvent($this->connection(), 'primary', $event);

        $start = $this->arrayAt($this->body($response), 'start');
        $this->assertArrayHasKey('dateTime', $start);
        $this->assertSame('America/New_York', $start['timeZone'] ?? null);
    }

    public function testCreateRecurringEventCarriesRrule(): void
    {
        [$client, $response] = $this->clientReturning(new MockResponse($this->json(['id' => 'evt-3'])));
        $event = new CalendarEvent(
            'Weekly',
            null,
            new \DateTimeImmutable('2026-08-17'),
            false,
            true,
            'UTC',
            ['frequency' => 'weekly', 'interval' => 1, 'byDay' => ['MO']],
            [],
        );

        $client->createEvent($this->connection(), 'primary', $event);

        $this->assertSame(['RRULE:FREQ=WEEKLY;BYDAY=MO'], $this->body($response)['recurrence'] ?? null);
    }

    public function testUpdateEvent(): void
    {
        [$client, $response] = $this->clientReturning(new MockResponse($this->json(['id' => 'evt-9'])));
        $event = new CalendarEvent('Renamed', 'body', new \DateTimeImmutable('2026-08-15'), true);

        $client->updateEvent($this->connection(), 'primary', 'evt-9', $event);

        $this->assertStringContainsString('/events/evt-9', $response->getRequestUrl());
        $summary = $this->body($response)['summary'] ?? null;
        $this->assertIsString($summary);
        $this->assertStringStartsWith('✓', $summary);
    }

    public function testDeleteEvent(): void
    {
        [$client, $response] = $this->clientReturning(new MockResponse('', ['http_code' => 204]));

        $client->deleteEvent($this->connection(), 'primary', 'evt-9');

        $this->assertSame('DELETE', $response->getRequestMethod());
        $this->assertStringContainsString('/events/evt-9', $response->getRequestUrl());
    }

    public function testDeleteGoneEventRaisesGone(): void
    {
        [$client] = $this->clientReturning(new MockResponse('', ['http_code' => 404]));

        try {
            $client->deleteEvent($this->connection(), 'primary', 'gone');
            $this->fail('Expected CalendarSyncException.');
        } catch (CalendarSyncException $e) {
            $this->assertTrue($e->eventGone);
        }
    }

    public function testListChangesParsesMoveAndCancel(): void
    {
        [$client] = $this->clientReturning(new MockResponse($this->json([
            'items' => [
                ['id' => 'a', 'status' => 'confirmed', 'start' => ['date' => '2026-08-20'], 'updated' => '2026-08-01T10:00:00Z'],
                ['id' => 'b', 'status' => 'cancelled'],
            ],
            'nextSyncToken' => 'tok-next',
        ])));

        $page = $client->listChanges($this->connection(), 'primary', null);

        $this->assertSame('tok-next', $page->nextSyncToken);
        $this->assertCount(2, $page->changes);
        $this->assertSame('a', $page->changes[0]->eventId);
        $this->assertFalse($page->changes[0]->cancelled);
        $this->assertSame('2026-08-20', $page->changes[0]->date?->format('Y-m-d'));
        $this->assertTrue($page->changes[1]->cancelled);
    }

    public function testListChangesReportsInvalidTokenOn410(): void
    {
        [$client] = $this->clientReturning(new MockResponse('', ['http_code' => 410]));

        $page = $client->listChanges($this->connection(), 'primary', 'stale');

        $this->assertTrue($page->tokenInvalid);
    }

    public function testSubscribeReturnsChannel(): void
    {
        [$client, $response] = $this->clientReturning(new MockResponse(
            $this->json(['resourceId' => 'res-1', 'expiration' => '1780000000000']),
        ));

        $result = $client->subscribe($this->connection(), 'https://example.test/calendar/webhook/google', 'secret');

        $this->assertNotSame('', $result->channelId);
        $this->assertSame('res-1', $result->resourceId);
        $this->assertStringContainsString('/events/watch', $response->getRequestUrl());
        $body = $this->body($response);
        $this->assertSame('secret', $body['token'] ?? null);
        $this->assertSame('web_hook', $body['type'] ?? null);
    }

    public function testUnsubscribeStopsChannel(): void
    {
        [$client, $response] = $this->clientReturning(new MockResponse('', ['http_code' => 204]));

        $client->unsubscribe($this->connection(), 'chan-1', 'res-1');

        $this->assertStringContainsString('/channels/stop', $response->getRequestUrl());
    }
}
