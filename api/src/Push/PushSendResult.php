<?php

namespace App\Push;

/**
 * Outcome of a single Web Push send. `subscriptionExpired` lets the caller
 * prune the matching subscription row when the browser's push service
 * returns 404/410, which is the standard signal that the user revoked the
 * permission or the browser garbage-collected the registration.
 */
final class PushSendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly bool $subscriptionExpired,
        public readonly ?int $statusCode = null,
        public readonly ?string $reason = null,
    ) {
    }
}
