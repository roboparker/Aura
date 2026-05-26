<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Thrown when the per-user 2FA rate limit is exhausted. Carries the
 * retry-after window so {@see \App\Security\TwoFactorJsonHandler} can
 * emit a 429 with a `Retry-After` header and a structured JSON body
 * the PWA's countdown can drive off.
 */
final class TooManyTwoFactorAttemptsException extends AuthenticationException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(sprintf(
            'Too many authentication attempts. Try again in %d seconds.',
            $retryAfterSeconds,
        ));
    }

    public function getMessageKey(): string
    {
        // Symfony's default message-key fallback would be the parent's
        // generic auth-error string; ours is already user-facing and
        // carries the retry seconds, so surface it as-is.
        return $this->getMessage();
    }
}
