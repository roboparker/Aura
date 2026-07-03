<?php

namespace App\Tests\Calendar;

use App\Calendar\CalendarEvent;
use App\Calendar\CalendarSyncException;
use App\Calendar\MicrosoftCalendarClient;
use App\Calendar\RecurrenceRuleMapper;
use App\Entity\OAuthConnection;
use App\Service\OAuthTokenService;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Microsoft Graph wire format (#582) exercised with a mocked HTTP client — the
 * request bodies + delta parsing the InMemory double can't cover.
 */
class MicrosoftCalendarClientTest extends KernelTestCase
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
        $connection = new OAuthConnection('microsoft');
        $this->tokens->store($connection, new AccessToken(['access_token' => 'tok-123', 'expires_in' => 3600]));

        return $connection;
    }

    /** @return array{0: MicrosoftCalendarClient, 1: MockResponse} */
    private function clientReturning(MockResponse $response): array
    {
        $mock = new MockHttpClient([$response]);

        return [new MicrosoftCalendarClient($mock, $this->tokens, $this->mapper), $response];
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
        $this->assertStringContainsString('/me/events', $response->getRequestUrl());
        $body = $this->body($response);
        $this->assertTrue($body['isAllDay'] ?? null);
        $this->assertSame('Do it', $body['subject'] ?? null);
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

        $body = $this->body($response);
        $this->assertFalse($body['isAllDay'] ?? null);
        $start = $this->arrayAt($body, 'start');
        $this->assertSame('America/New_York', $start['timeZone'] ?? null);
    }

    public function testCreateRecurringEventCarriesPattern(): void
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

        $recurrence = $this->arrayAt($this->body($response), 'recurrence');
        $pattern = $this->arrayAt($recurrence, 'pattern');
        $this->assertSame('weekly', $pattern['type'] ?? null);
    }

    public function testUpdateEvent(): void
    {
        [$client, $response] = $this->clientReturning(new MockResponse($this->json(['id' => 'evt-9'])));
        $event = new CalendarEvent('Renamed', null, new \DateTimeImmutable('2026-08-15'), true);

        $client->updateEvent($this->connection(), 'primary', 'evt-9', $event);

        $this->assertSame('PATCH', $response->getRequestMethod());
        $this->assertStringContainsString('/me/events/evt-9', $response->getRequestUrl());
        // Completed → check-marked subject.
        $subject = $this->body($response)['subject'] ?? null;
        $this->assertIsString($subject);
        $this->assertStringStartsWith('✓', $subject);
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

    public function testListChangesParsesDeltaAndDeltaLink(): void
    {
        [$client] = $this->clientReturning(new MockResponse($this->json([
            'value' => [
                ['id' => 'a', 'start' => ['dateTime' => '2026-08-20T00:00:00.0000000', 'timeZone' => 'UTC'], 'lastModifiedDateTime' => '2026-08-01T10:00:00Z'],
                ['id' => 'b', '@removed' => ['reason' => 'deleted']],
            ],
            '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=xyz',
        ])));

        $page = $client->listChanges($this->connection(), 'primary', null);

        $this->assertStringContainsString('deltatoken=xyz', (string) $page->nextSyncToken);
        $this->assertCount(2, $page->changes);
        $this->assertSame('2026-08-20', $page->changes[0]->date?->format('Y-m-d'));
        $this->assertTrue($page->changes[1]->cancelled);
    }

    public function testListChangesReportsInvalidTokenOn410(): void
    {
        [$client] = $this->clientReturning(new MockResponse('', ['http_code' => 410]));

        $page = $client->listChanges($this->connection(), 'primary', 'stale-url');

        $this->assertTrue($page->tokenInvalid);
    }

    public function testSubscribeReturnsSubscriptionId(): void
    {
        [$client, $response] = $this->clientReturning(new MockResponse(
            $this->json(['id' => 'sub-1', 'expirationDateTime' => '2026-08-03T00:00:00Z']),
        ));

        $result = $client->subscribe($this->connection(), 'https://example.test/calendar/webhook/microsoft', 'secret');

        $this->assertSame('sub-1', $result->channelId);
        $this->assertStringContainsString('/subscriptions', $response->getRequestUrl());
        $body = $this->body($response);
        $this->assertSame('secret', $body['clientState'] ?? null);
        $this->assertSame('/me/events', $body['resource'] ?? null);
    }

    public function testUnsubscribeDeletesSubscription(): void
    {
        [$client, $response] = $this->clientReturning(new MockResponse('', ['http_code' => 204]));

        $client->unsubscribe($this->connection(), 'sub-1', null);

        $this->assertSame('DELETE', $response->getRequestMethod());
        $this->assertStringContainsString('/subscriptions/sub-1', $response->getRequestUrl());
    }
}
