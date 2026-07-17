<?php

namespace App\MessageHandler;

use App\Message\SentryTestFailure;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Fails on purpose so the admin verification surface can confirm that errors in
 * a background job reach Sentry (via the bundle's Messenger integration).
 *
 * Throwing UnrecoverableMessageHandlingException means no retries: the job
 * fails once, is captured a single time, and dead-letters into the `failed`
 * transport (bin/console messenger:failed:show) instead of a 4× retry storm.
 */
#[AsMessageHandler]
final class SentryTestFailureHandler
{
    public function __invoke(SentryTestFailure $message): never
    {
        throw new UnrecoverableMessageHandlingException(
            'Sentry worker test error — triggered from the admin verification page.',
        );
    }
}
