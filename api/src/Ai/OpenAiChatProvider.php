<?php

declare(strict_types=1);

namespace App\Ai;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenAI chat completions over Symfony HttpClient — no SDK, matching
 * {@see \App\Billing\StripeGateway} and the calendar clients.
 *
 * Our surface is one endpoint. Everything OpenAI-shaped — the `messages`
 * array, the `usage` block, `finish_reason` strings, error envelopes — is
 * translated at this boundary and never escapes it.
 *
 * The key comes from env and is blank on a fresh checkout; {@see isConfigured()}
 * is what lets the rest of the app degrade rather than fatal. Note the
 * `string:` cast on the autowired env: bare `default::` resolves an unset var
 * to null, which a non-nullable string parameter rejects at container build
 * time — the trap that has taken this codebase down before.
 */
final class OpenAiChatProvider implements ChatProviderInterface
{
    public const NAME = 'openai';

    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    /**
     * A small, cheap model by default. Agent chat is high-volume and
     * low-stakes, and the cost of the default is what an unattended loop
     * multiplies — so the default should be the cheap one and pinning a
     * larger model should be a deliberate act.
     */
    private const DEFAULT_MODEL = 'gpt-4o-mini';

    private const TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(string:default::OPENAI_API_KEY)%')]
        private readonly string $apiKey,
        #[Autowire('%env(string:default::OPENAI_MODEL)%')]
        private readonly string $configuredModel = '',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function isConfigured(): bool
    {
        return '' !== $this->apiKey;
    }

    public function defaultModel(): string
    {
        return '' !== $this->configuredModel ? $this->configuredModel : self::DEFAULT_MODEL;
    }

    public function complete(ChatRequest $request): ChatResponse
    {
        if (!$this->isConfigured()) {
            throw ChatProviderException::notConfigured(self::NAME);
        }

        $payload = [
            'model' => $request->model,
            'messages' => array_map(
                static fn (ChatMessage $m) => ['role' => $m->role, 'content' => $m->content],
                $request->messages,
            ),
            // Always sent. The meter reserved against this number, so letting
            // the model run past it would mean charging for tokens we never
            // reserved — the overspend this design exists to prevent.
            'max_completion_tokens' => $request->maxOutputTokens,
        ];
        if (null !== $request->temperature) {
            $payload['temperature'] = $request->temperature;
        }

        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();
            // `false` stops HttpClient throwing on 4xx/5xx so we can map the
            // status onto our own retryable/not distinction.
            $body = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            $this->logger->warning('OpenAI request failed', ['exception' => $e]);
            throw ChatProviderException::transport(self::NAME, $e);
        } catch (\JsonException | \Throwable $e) {
            $this->logger->warning('OpenAI response could not be read', ['exception' => $e]);
            throw ChatProviderException::transport(self::NAME, $e);
        }

        if (429 === $status) {
            throw ChatProviderException::rateLimited(self::NAME);
        }
        if ($status >= 500) {
            throw ChatProviderException::unavailable(self::NAME, $status);
        }
        if ($status >= 400) {
            throw ChatProviderException::refused(self::NAME, $status, $this->errorMessage($body));
        }

        return $this->toResponse($request, $body);
    }

    /**
     * @param array<int|string, mixed> $body
     */
    private function toResponse(ChatRequest $request, array $body): ChatResponse
    {
        $choices = $body['choices'] ?? null;
        if (!is_array($choices) || !isset($choices[0]) || !is_array($choices[0])) {
            throw ChatProviderException::malformed(self::NAME, 'no choices in the response');
        }
        $choice = $choices[0];
        $message = $choice['message'] ?? null;
        $content = is_array($message) ? ($message['content'] ?? null) : null;
        if (!is_string($content)) {
            throw ChatProviderException::malformed(self::NAME, 'the first choice carried no text');
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];
        $promptTokens = $this->intOr($usage['prompt_tokens'] ?? null, $request->estimatedPromptTokens());
        // Falling back to a character estimate rather than 0 matters: a
        // response with no usage block would otherwise be metered as free, and
        // "free" is the one wrong answer a spend control must never give.
        $completionTokens = $this->intOr(
            $usage['completion_tokens'] ?? null,
            (int) ceil(mb_strlen($content) / 4),
        );

        return new ChatResponse(
            content: $content,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            model: is_string($body['model'] ?? null) ? $body['model'] : $request->model,
            finishReason: $this->finishReason($choice['finish_reason'] ?? null),
        );
    }

    private function intOr(mixed $value, int $fallback): int
    {
        return is_int($value) && $value >= 0 ? $value : $fallback;
    }

    /** Normalise OpenAI's `finish_reason` onto our own vocabulary. */
    private function finishReason(mixed $raw): string
    {
        return match ($raw) {
            'stop' => ChatResponse::FINISH_STOP,
            'length' => ChatResponse::FINISH_LENGTH,
            'content_filter' => ChatResponse::FINISH_FILTER,
            default => ChatResponse::FINISH_OTHER,
        };
    }

    /**
     * @param array<int|string, mixed> $body
     */
    private function errorMessage(array $body): string
    {
        $error = $body['error'] ?? null;
        if (is_array($error) && is_string($error['message'] ?? null)) {
            return $error['message'];
        }

        return '';
    }
}
