<?php

namespace App\Push;

use App\Entity\PushSubscription;

/**
 * Abstraction the dispatcher talks to. The prod implementation
 * (`WebPushSender`) wraps minishlink/web-push; the test env aliases this
 * to an in-memory recorder so the suite never opens a real socket.
 */
interface PushSenderInterface
{
    public function send(PushSubscription $subscription, PushPayload $payload): PushSendResult;
}
