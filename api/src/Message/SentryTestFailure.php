<?php

namespace App\Message;

/**
 * Marker message dispatched by the admin Sentry verification surface
 * (SentryTestController). Its handler throws on purpose so we can confirm that
 * worker-side errors reach Sentry once a DSN is configured. Routed to `async`
 * (messenger.yaml) so it runs on the worker process, not the request.
 */
final class SentryTestFailure
{
}
