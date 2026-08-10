<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\ChatProviderException;
use App\Ai\ChatProviderInterface;
use App\Ai\ChatRequest;
use App\Ai\ChatResponse;

/**
 * The test double for {@see ChatProviderInterface}, mirroring
 * {@see \App\Tests\Billing\InMemoryStripeGateway} and
 * {@see \App\Tests\Calendar\InMemoryCalendarClient}: the suite must never make
 * a paid API call, and the credit tests need to dictate exactly what a call
 * "cost".
 *
 * Reports itself configured by default so the metering path runs end-to-end;
 * {@see setConfigured()} turns that off for the "no provider" case.
 */
final class InMemoryChatProvider implements ChatProviderInterface
{
    public const NAME = 'openai';

    /** @var list<ChatRequest> */
    public array $requests = [];

    private bool $configured = true;

    private string $reply = 'Hello from the test model.';

    private int $promptTokens = 100;

    private int $completionTokens = 50;

    private string $finishReason = ChatResponse::FINISH_STOP;

    private ?\Throwable $failure = null;

    /** @var (\Closure(ChatRequest): void)|null */
    private ?\Closure $onComplete = null;

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function defaultModel(): string
    {
        return 'test-model';
    }

    public function complete(ChatRequest $request): ChatResponse
    {
        $this->requests[] = $request;

        // Runs at the moment a real provider would be mid-flight, which is the
        // only place a test can observe that the reservation was taken *before*
        // the call rather than after it.
        if (null !== $this->onComplete) {
            ($this->onComplete)($request);
        }

        if (null !== $this->failure) {
            throw $this->failure;
        }
        if (!$this->configured) {
            throw ChatProviderException::notConfigured(self::NAME);
        }

        return new ChatResponse(
            content: $this->reply,
            promptTokens: $this->promptTokens,
            completionTokens: $this->completionTokens,
            model: $this->defaultModel(),
            finishReason: $this->finishReason,
        );
    }

    /** Simulate the model stopping at its output ceiling. */
    public function setFinishReason(string $finishReason): void
    {
        $this->finishReason = $finishReason;
    }

    public function setConfigured(bool $configured): void
    {
        $this->configured = $configured;
    }

    /** Dictate the usage the next call reports, so a test can spend a known amount. */
    public function setUsage(int $promptTokens, int $completionTokens): void
    {
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;
    }

    public function setReply(string $reply): void
    {
        $this->reply = $reply;
    }

    /** Make the next call blow up, to exercise the release path. */
    public function failWith(?\Throwable $failure): void
    {
        $this->failure = $failure;
    }

    /**
     * Run something while the call is "in flight".
     *
     * @param (\Closure(ChatRequest): void)|null $hook
     */
    public function onComplete(?\Closure $hook): void
    {
        $this->onComplete = $hook;
    }

    public function reset(): void
    {
        $this->requests = [];
        $this->configured = true;
        $this->failure = null;
        $this->onComplete = null;
        $this->reply = 'Hello from the test model.';
        $this->promptTokens = 100;
        $this->completionTokens = 50;
        $this->finishReason = ChatResponse::FINISH_STOP;
    }
}
