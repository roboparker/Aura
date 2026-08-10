<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * A completion to ask a provider for.
 *
 * `maxOutputTokens` is not just a knob — it is the ceiling the credit meter
 * reserves against, so it must always be set to something finite. A request
 * with no bound cannot be reserved for, and an unreservable request is exactly
 * the unmetered-spend failure the design exists to prevent.
 */
final class ChatRequest
{
    /**
     * A conservative cap for a chat turn. Generous enough for a real answer,
     * small enough that a runaway loop is bounded by arithmetic rather than
     * by someone noticing.
     */
    public const DEFAULT_MAX_OUTPUT_TOKENS = 1024;

    /**
     * @param list<ChatMessage> $messages ordered oldest-first
     * @param string            $model    provider-specific identifier; ask the
     *                                    provider for its {@see ChatProviderInterface::defaultModel()}
     *                                    rather than hard-coding one at a call site
     */
    public function __construct(
        public readonly string $model,
        public readonly array $messages,
        public readonly int $maxOutputTokens = self::DEFAULT_MAX_OUTPUT_TOKENS,
        public readonly ?float $temperature = null,
    ) {
    }

    /**
     * A cheap upper-ish estimate of the prompt size, used only to size the
     * credit reservation before the call.
     *
     * Four characters per token is the usual rule of thumb for English; it is
     * wrong in both directions for other scripts and for code. That is
     * tolerable *because the estimate is never the charge* — the reservation is
     * replaced by the provider's reported token counts the moment the call
     * returns. Its only job is to stop a request that obviously cannot be
     * afforded, so erring high is the safe direction and is what the ceil()
     * plus per-message overhead does.
     */
    public function estimatedPromptTokens(): int
    {
        $tokens = 0;
        foreach ($this->messages as $message) {
            // +4 per message approximates the role/delimiter framing every
            // provider adds around the content.
            $tokens += (int) ceil(mb_strlen($message->content) / 4) + 4;
        }

        return $tokens;
    }

    /** The most this request could possibly cost, in tokens. */
    public function estimatedTotalTokens(): int
    {
        return $this->estimatedPromptTokens() + $this->maxOutputTokens;
    }
}
