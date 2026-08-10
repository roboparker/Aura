<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * What a provider gave back.
 *
 * The token counts are the whole reason this type carries more than a string:
 * they are what the credit meter reconciles the reservation against, so a
 * provider that cannot report usage cannot be metered honestly. An
 * implementation that has no usage figures must estimate and say so rather
 * than report zero — zero would silently make its traffic free.
 */
final class ChatResponse
{
    public function __construct(
        public readonly string $content,
        public readonly int $promptTokens,
        public readonly int $completionTokens,
        public readonly string $model,
        /**
         * Why generation stopped, normalised to our own vocabulary. `length`
         * means the answer was cut off at `maxOutputTokens` — worth surfacing,
         * because to a reader it looks like the agent simply trailed off.
         */
        public readonly string $finishReason = self::FINISH_STOP,
    ) {
    }

    public const FINISH_STOP = 'stop';
    public const FINISH_LENGTH = 'length';
    public const FINISH_FILTER = 'content_filter';
    public const FINISH_OTHER = 'other';

    public function totalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function wasTruncated(): bool
    {
        return self::FINISH_LENGTH === $this->finishReason;
    }
}
