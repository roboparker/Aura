<?php

namespace App\Tests\Push;

use App\Entity\PushSubscription;
use App\Push\PushPayload;
use App\Push\PushSendResult;
use App\Push\PushSenderInterface;

/**
 * Recording test double for `PushSenderInterface`. Captures every send so
 * tests can assert what would have been delivered, and lets a test mark
 * specific endpoints as "expired" to exercise the dispatcher's pruning of
 * dead subscription rows.
 */
final class InMemoryPushSender implements PushSenderInterface
{
    /** @var list<array{endpoint: string, payload: PushPayload}> */
    public array $sends = [];

    /** @var list<string> Endpoints to report back as expired (404/410). */
    public array $expiredEndpoints = [];

    public function send(PushSubscription $subscription, PushPayload $payload): PushSendResult
    {
        $endpoint = $subscription->getEndpoint();
        $this->sends[] = ['endpoint' => $endpoint, 'payload' => $payload];

        $expired = in_array($endpoint, $this->expiredEndpoints, true);
        return new PushSendResult(
            success: !$expired,
            subscriptionExpired: $expired,
            statusCode: $expired ? 410 : 201,
        );
    }

    public function reset(): void
    {
        $this->sends = [];
        $this->expiredEndpoints = [];
    }
}
