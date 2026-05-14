<?php

namespace App\Push;

use App\Entity\PushSubscription as PushSubscriptionEntity;
use Minishlink\WebPush\Subscription as WebPushSubscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Real Web Push sender backed by minishlink/web-push. VAPID keys are
 * pulled from env vars (see docs/deployment.md). When any of the three
 * VAPID slots is empty the sender no-ops with a warning so a freshly
 * checked-out dev environment doesn't crash the dispatcher cron — the
 * in-app notifications still land, just no push leaves the box.
 */
final class WebPushSender implements PushSenderInterface
{
    public function __construct(
        #[Autowire('%env(default::VAPID_PUBLIC_KEY)%')]
        private readonly ?string $publicKey,
        #[Autowire('%env(default::VAPID_PRIVATE_KEY)%')]
        private readonly ?string $privateKey,
        #[Autowire('%env(default::VAPID_SUBJECT)%')]
        private readonly ?string $subject,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function send(PushSubscriptionEntity $subscription, PushPayload $payload): PushSendResult
    {
        if (!$this->isConfigured()) {
            $this->logger->warning('Web Push send skipped: VAPID keys are not configured.');
            return new PushSendResult(false, false, null, 'VAPID keys not configured');
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $this->subject,
                'publicKey' => $this->publicKey,
                'privateKey' => $this->privateKey,
            ],
        ]);

        $vendorSub = WebPushSubscription::create([
            'endpoint' => $subscription->getEndpoint(),
            'publicKey' => $subscription->getP256dh(),
            'authToken' => $subscription->getAuth(),
        ]);

        try {
            $report = $webPush->sendOneNotification($vendorSub, $payload->toJson());
        } catch (\ErrorException | \RuntimeException $e) {
            // Network blip or malformed payload — log and let the next
            // dispatcher pass try again. Don't propagate; one bad endpoint
            // must not abort delivery for the remaining recipients.
            $this->logger->warning('Web Push send failed: {reason}', [
                'reason' => $e->getMessage(),
                'endpoint' => $subscription->getEndpoint(),
            ]);
            return new PushSendResult(false, false, null, $e->getMessage());
        }

        return new PushSendResult(
            success: $report->isSuccess(),
            subscriptionExpired: $report->isSubscriptionExpired(),
            statusCode: $report->getResponse()?->getStatusCode(),
            reason: $report->getReason(),
        );
    }

    private function isConfigured(): bool
    {
        return null !== $this->publicKey && '' !== $this->publicKey
            && null !== $this->privateKey && '' !== $this->privateKey
            && null !== $this->subject && '' !== $this->subject;
    }
}
