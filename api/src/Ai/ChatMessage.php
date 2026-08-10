<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * One turn in a conversation, in **our** vocabulary rather than a provider's.
 *
 * Deliberately the smallest thing that works: a role and some text. Providers
 * have far richer message shapes — content parts, tool calls, images,
 * annotations — and every one of those we adopt becomes something the *second*
 * provider has to be translated into. Keeping the seam this narrow is what
 * makes adding a second model one class rather than a refactor.
 *
 * Anything richer that a provider genuinely needs belongs inside that
 * provider's implementation, built from these fields.
 */
final class ChatMessage
{
    /** The instructions the agent operates under. Never user-authored. */
    public const ROLE_SYSTEM = 'system';

    /** A person talking to the agent. */
    public const ROLE_USER = 'user';

    /** Something the agent previously said. */
    public const ROLE_ASSISTANT = 'assistant';

    public const ROLES = [self::ROLE_SYSTEM, self::ROLE_USER, self::ROLE_ASSISTANT];

    private function __construct(
        public readonly string $role,
        public readonly string $content,
    ) {
    }

    public static function system(string $content): self
    {
        return new self(self::ROLE_SYSTEM, $content);
    }

    public static function user(string $content): self
    {
        return new self(self::ROLE_USER, $content);
    }

    public static function assistant(string $content): self
    {
        return new self(self::ROLE_ASSISTANT, $content);
    }

    /**
     * Rebuild a turn from a stored role string, falling back to `user`.
     *
     * The fallback is not laziness: this reads rows written by an earlier
     * version of the app, and `user` is the safe landing spot — content
     * mislabelled as user input is treated as data, whereas defaulting to
     * `system` would promote stored text to instructions. That is precisely
     * the prompt-injection route the design is meant to close.
     */
    public static function fromStored(string $role, string $content): self
    {
        return new self(
            in_array($role, [self::ROLE_ASSISTANT, self::ROLE_SYSTEM], true) ? $role : self::ROLE_USER,
            $content,
        );
    }
}
