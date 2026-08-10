<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\ChatMessage;
use App\Ai\ChatProviderException;
use App\Ai\ChatRequest;
use App\Ai\ChatResponse;
use App\Ai\OpenAiChatProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The OpenAI translation layer — the half of the provider seam the in-memory
 * double can't cover, since the whole point of the double is that it never
 * speaks OpenAI's dialect.
 *
 * What's asserted is the boundary: our types in, our types out, and every
 * OpenAI-shaped failure collapsed onto {@see ChatProviderException} with the
 * retryable/not distinction intact — because that distinction is the only
 * thing a caller is allowed to branch on.
 */
class OpenAiChatProviderTest extends TestCase
{
    public function testAnUnconfiguredInstanceRefusesWithoutCallingOut(): void
    {
        $client = new MockHttpClient([]);
        $provider = new OpenAiChatProvider($client, '');

        $this->assertFalse($provider->isConfigured());
        $this->expectException(ChatProviderException::class);
        $provider->complete($this->request());
    }

    public function testASuccessfulCompletionIsTranslatedIntoOurTypes(): void
    {
        $client = new MockHttpClient([
            new MockResponse((string) json_encode([
                'model' => 'gpt-4o-mini-2026-01-01',
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Two.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 42, 'completion_tokens' => 7],
            ])),
        ]);

        $response = (new OpenAiChatProvider($client, 'sk-test'))->complete($this->request());

        $this->assertSame('Two.', $response->content);
        $this->assertSame(42, $response->promptTokens);
        $this->assertSame(7, $response->completionTokens);
        $this->assertSame(49, $response->totalTokens());
        $this->assertSame('gpt-4o-mini-2026-01-01', $response->model);
        $this->assertSame(ChatResponse::FINISH_STOP, $response->finishReason);
        $this->assertFalse($response->wasTruncated());
    }

    public function testATruncatedAnswerSaysSo(): void
    {
        // Worth surfacing: to a reader, a `length` stop looks like the agent
        // simply trailed off mid-sentence.
        $client = new MockHttpClient([
            new MockResponse((string) json_encode([
                'choices' => [[
                    'message' => ['content' => 'It began'],
                    'finish_reason' => 'length',
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4],
            ])),
        ]);

        $response = (new OpenAiChatProvider($client, 'sk-test'))->complete($this->request());

        $this->assertTrue($response->wasTruncated());
    }

    public function testAResponseWithoutUsageIsEstimatedRatherThanFree(): void
    {
        // The one wrong answer a spend control must never give is "zero".
        $client = new MockHttpClient([
            new MockResponse((string) json_encode([
                'choices' => [['message' => ['content' => str_repeat('a', 400)], 'finish_reason' => 'stop']],
            ])),
        ]);

        $response = (new OpenAiChatProvider($client, 'sk-test'))->complete($this->request());

        $this->assertGreaterThan(0, $response->promptTokens);
        $this->assertGreaterThan(0, $response->completionTokens);
    }

    public function testTheOutputCeilingIsAlwaysSentToTheProvider(): void
    {
        // The meter reserved against this number; letting the model run past it
        // would mean charging for tokens nothing reserved.
        $seen = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seen) {
            $seen = json_decode((string) ($options['body'] ?? '{}'), true);

            return new MockResponse((string) json_encode([
                'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ]));
        });

        (new OpenAiChatProvider($client, 'sk-test'))->complete($this->request(maxOutputTokens: 321));

        $this->assertIsArray($seen);
        $this->assertSame(321, $seen['max_completion_tokens'] ?? null);
    }

    /**
     * @return iterable<string, array{0: int, 1: bool}>
     */
    public static function failureStatuses(): iterable
    {
        yield 'rate limited is worth retrying' => [429, true];
        yield 'their outage is worth retrying' => [503, true];
        yield 'a bad key never becomes good' => [401, false];
        yield 'a refused request stays refused' => [400, false];
    }

    /**
     * @dataProvider failureStatuses
     */
    public function testFailuresCarryWhetherARetryCouldHelp(int $status, bool $retryable): void
    {
        $client = new MockHttpClient([
            new MockResponse((string) json_encode(['error' => ['message' => 'nope']]), ['http_code' => $status]),
        ]);

        try {
            (new OpenAiChatProvider($client, 'sk-test'))->complete($this->request());
            $this->fail('A ' . $status . ' should not have produced a response.');
        } catch (ChatProviderException $e) {
            $this->assertSame($retryable, $e->retryable);
        }
    }

    public function testAnUnrecognisedBodyIsOurBugNotABlip(): void
    {
        $client = new MockHttpClient([
            new MockResponse((string) json_encode(['choices' => []])),
        ]);

        try {
            (new OpenAiChatProvider($client, 'sk-test'))->complete($this->request());
            $this->fail('An empty choices array is not a usable response.');
        } catch (ChatProviderException $e) {
            // Not retryable: the same request would parse the same way again.
            $this->assertFalse($e->retryable);
        }
    }

    public function testTheDefaultModelCanBeOverriddenByConfiguration(): void
    {
        $client = new MockHttpClient([]);

        $this->assertSame('gpt-4o-mini', (new OpenAiChatProvider($client, 'sk-test'))->defaultModel());
        $this->assertSame('gpt-5', (new OpenAiChatProvider($client, 'sk-test', 'gpt-5'))->defaultModel());
    }

    private function request(int $maxOutputTokens = ChatRequest::DEFAULT_MAX_OUTPUT_TOKENS): ChatRequest
    {
        return new ChatRequest(
            model: 'gpt-4o-mini',
            messages: [ChatMessage::system('Be brief.'), ChatMessage::user('One plus one?')],
            maxOutputTokens: $maxOutputTokens,
        );
    }
}
