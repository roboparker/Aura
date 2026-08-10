<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * A model provider could not answer.
 *
 * Every failure mode collapses to this one type on purpose — the caller's
 * response is identical whichever it is (release the credit reservation, tell
 * the user the agent is unavailable), and provider-specific error taxonomies
 * are exactly the thing {@see ChatProviderInterface} exists to keep out.
 *
 * `retryable` is the one distinction worth preserving, because it changes what
 * a caller may do next: a rate limit or a 5xx is worth trying again, a refused
 * or malformed request never is.
 */
final class ChatProviderException extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly bool $retryable,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function transport(string $provider, ?\Throwable $previous = null): self
    {
        return new self(sprintf('Could not reach the %s API.', $provider), true, $previous);
    }

    public static function rateLimited(string $provider): self
    {
        return new self(sprintf('The %s API is rate limiting us.', $provider), true);
    }

    /** A 5xx — their problem, and it may well pass. */
    public static function unavailable(string $provider, int $status): self
    {
        return new self(sprintf('The %s API returned %d.', $provider, $status), true);
    }

    /** A 4xx we caused: bad key, bad model, refused content. Retrying repeats it. */
    public static function refused(string $provider, int $status, string $detail = ''): self
    {
        return new self(
            rtrim(sprintf('The %s API refused the request (%d). %s', $provider, $status, $detail)),
            false,
        );
    }

    /** A 200 whose shape we don't recognise — treated as our bug, not a blip. */
    public static function malformed(string $provider, string $detail): self
    {
        return new self(sprintf('Unexpected response from the %s API: %s', $provider, $detail), false);
    }

    public static function notConfigured(string $provider): self
    {
        return new self(sprintf('No %s credentials are configured on this instance.', $provider), false);
    }
}
