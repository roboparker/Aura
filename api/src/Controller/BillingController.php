<?php

declare(strict_types=1);

namespace App\Controller;

use App\Billing\BillingException;
use App\Billing\StripeGatewayInterface;
use App\Entity\Space;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Service\UsageLimiter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Billing surface for the paid "Team" plan (#billing).
 *
 * Upgrade + management run through Stripe-hosted pages — we only mint session
 * URLs and let Stripe own the card/PCI surface:
 *  - POST /spaces/{id}/billing/checkout — space-admin → Checkout Session URL
 *  - POST /spaces/{id}/billing/portal   — space-admin → Billing Portal URL
 *  - GET  /spaces/{id}/billing          — any member → plan + limits summary
 *  - POST /billing/webhook              — Stripe (signed) → subscription sync
 *
 * The subscription row is written ONLY by the webhook, off the back of
 * Stripe's authoritative `customer.subscription.*` events — checkout itself
 * never persists a Subscription, so a user bailing on the hosted page leaves
 * no half-built billing state. Stamping `metadata[space_id]` onto the
 * subscription at checkout is what lets the webhook resolve our space.
 */
class BillingController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private StripeGatewayInterface $stripe,
        private SubscriptionRepository $subscriptions,
        private UsageLimiter $usageLimiter,
        private LoggerInterface $logger,
        #[Autowire('%env(default::STRIPE_PRICE_TEAM_MONTHLY)%')]
        private string $priceMonthly,
        #[Autowire('%env(default::STRIPE_PRICE_TEAM_YEARLY)%')]
        private string $priceYearly,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    #[Route('/spaces/{id}/billing/checkout', name: 'billing_checkout', methods: ['POST'])]
    public function checkout(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $space = $this->resolveAdminSpace($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }
        if ($space->getIsPersonal()) {
            return $this->json(['error' => 'Personal spaces cannot be subscribed.'], 422);
        }
        if (!$this->stripe->isConfigured()) {
            return $this->json(['error' => 'Billing is not available on this instance.'], 503);
        }

        $interval = $this->readInterval($request);
        $priceId = Subscription::INTERVAL_YEAR === $interval ? $this->priceYearly : $this->priceMonthly;
        if ('' === $priceId) {
            return $this->json(['error' => 'The selected plan is not configured.'], 503);
        }

        // Reuse the Stripe customer if this space has subscribed before, so a
        // re-subscribe lands on the same customer (one card on file, one
        // invoice history). $user is non-null here (resolveAdminSpace gates it).
        $existing = $this->subscriptions->findActiveForSpace($space);
        $customerId = $existing?->getStripeCustomerId();
        $quantity = max(1, \count($space->getEffectiveUsers()));

        try {
            $url = $this->stripe->createCheckoutSession(
                priceId: $priceId,
                quantity: $quantity,
                successUrl: $this->frontendUrl . '/spaces/' . $id . '/settings?billing=success',
                cancelUrl: $this->frontendUrl . '/spaces/' . $id . '/settings?billing=cancelled',
                customerId: $customerId,
                customerEmail: null !== $customerId ? null : $user?->getEmail(),
                metadata: ['space_id' => $id],
            );
        } catch (BillingException $e) {
            $this->logger->error('Stripe checkout session failed', ['exception' => $e, 'space' => $id]);
            return $this->json(['error' => 'Could not start checkout. Please try again.'], 502);
        }

        return $this->json(['url' => $url]);
    }

    #[Route('/spaces/{id}/billing/portal', name: 'billing_portal', methods: ['POST'])]
    public function portal(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $space = $this->resolveAdminSpace($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }

        $subscription = $this->subscriptions->findActiveForSpace($space);
        $customerId = $subscription?->getStripeCustomerId();
        if (null === $customerId) {
            return $this->json(['error' => 'This space has no billing to manage.'], 409);
        }
        if (!$this->stripe->isConfigured()) {
            return $this->json(['error' => 'Billing is not available on this instance.'], 503);
        }

        try {
            $url = $this->stripe->createBillingPortalSession(
                $customerId,
                $this->frontendUrl . '/spaces/' . $id . '/settings',
            );
        } catch (BillingException $e) {
            $this->logger->error('Stripe portal session failed', ['exception' => $e, 'space' => $id]);
            return $this->json(['error' => 'Could not open the billing portal. Please try again.'], 502);
        }

        return $this->json(['url' => $url]);
    }

    #[Route('/spaces/{id}/billing', name: 'billing_status', methods: ['GET'])]
    public function status(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $space = $this->em->getRepository(Space::class)->find($id);
        // Hide existence from non-members, mirroring the access extensions.
        if (null === $space || !($this->isGranted('ROLE_ADMIN') || $space->hasMember($user))) {
            return $this->json(['error' => 'Space not found.'], 404);
        }

        $subscription = $this->subscriptions->findActiveForSpace($space);

        return $this->json([
            'plan' => $subscription?->getPlan() ?? 'free',
            'status' => $subscription?->getStatus(),
            'active' => null !== $subscription,
            'billingAvailable' => $this->stripe->isConfigured(),
            'seats' => $subscription?->getSeats(),
            'billingInterval' => $subscription?->getBillingInterval(),
            'currentPeriodEnd' => $subscription?->getCurrentPeriodEnd()?->format(\DateTimeInterface::ATOM),
            'cancelAtPeriodEnd' => $subscription?->getCancelAtPeriodEnd() ?? false,
            'isAdmin' => $space->isAdmin($user) || $this->isGranted('ROLE_ADMIN'),
            'limits' => [
                'freeSpaceMemberLimit' => $this->usageLimiter->freeSpaceMemberLimit(),
                'memberCount' => \count($space->getEffectiveUsers()),
                'freeMcpDailyLimit' => $this->usageLimiter->freeMcpDailyLimit(),
            ],
        ]);
    }

    #[Route('/billing/webhook', name: 'billing_webhook', methods: ['POST'])]
    public function webhook(Request $request): Response
    {
        $event = $this->stripe->parseWebhookEvent(
            $request->getContent(),
            $request->headers->get('Stripe-Signature', ''),
        );
        if (null === $event) {
            return new Response('Invalid signature.', Response::HTTP_BAD_REQUEST);
        }

        $type = $this->dig($event, ['type']);
        $object = $this->dig($event, ['data', 'object']);
        if (!is_string($type) || !is_array($object)) {
            // Acknowledge unrecognised shapes so Stripe stops retrying.
            return new Response('', Response::HTTP_OK);
        }

        if ('customer.subscription.deleted' === $type) {
            $this->syncSubscription($object, true);
        } elseif ('customer.subscription.created' === $type || 'customer.subscription.updated' === $type) {
            $this->syncSubscription($object, false);
        }
        // Any other event type is acknowledged and ignored.

        return new Response('', Response::HTTP_OK);
    }

    /**
     * Upsert our mirror row from a Stripe subscription object. Idempotent —
     * keyed by the Stripe subscription id, so retries and out-of-order events
     * converge on the latest state. When $deleted the status is forced to
     * canceled regardless of the payload.
     *
     * @param array<mixed, mixed> $sub
     */
    private function syncSubscription(array $sub, bool $deleted): void
    {
        $stripeSubId = $this->stringAt($sub, ['id']);
        if (null === $stripeSubId) {
            $this->logger->warning('Stripe subscription webhook missing id.');
            return;
        }

        $row = $this->subscriptions->findByStripeSubscriptionId($stripeSubId);
        if (null === $row) {
            $spaceId = $this->stringAt($sub, ['metadata', 'space_id']);
            $space = null !== $spaceId ? $this->em->getRepository(Space::class)->find($spaceId) : null;
            if (!$space instanceof Space) {
                $this->logger->warning('Stripe subscription webhook could not resolve space.', [
                    'subscription' => $stripeSubId,
                    'space_id' => $spaceId,
                ]);
                return;
            }
            $row = new Subscription();
            $row->setSpace($space)->setStripeSubscriptionId($stripeSubId);
            $this->em->persist($row);
        }

        $status = $deleted ? Subscription::STATUS_CANCELED : ($this->stringAt($sub, ['status']) ?? Subscription::STATUS_INCOMPLETE);
        $row->setStatus($status);

        $customerId = $this->stringAt($sub, ['customer']);
        if (null !== $customerId) {
            $row->setStripeCustomerId($customerId);
        }
        $row->setCancelAtPeriodEnd(true === $this->dig($sub, ['cancel_at_period_end']));

        $periodEnd = $this->dig($sub, ['current_period_end']);
        if (is_int($periodEnd)) {
            $row->setCurrentPeriodEnd((new \DateTimeImmutable())->setTimestamp($periodEnd));
        }

        $priceId = $this->stringAt($sub, ['items', 'data', 0, 'price', 'id']);
        if (null !== $priceId) {
            $row->setStripePriceId($priceId);
        }
        $interval = $this->stringAt($sub, ['items', 'data', 0, 'price', 'recurring', 'interval']);
        if (null !== $interval) {
            $row->setBillingInterval($interval);
        }
        $quantity = $this->dig($sub, ['items', 'data', 0, 'quantity']);
        if (is_int($quantity)) {
            $row->setSeats($quantity);
        }

        $row->touch();
        $this->em->flush();
    }

    private function readInterval(Request $request): string
    {
        $content = $request->getContent();
        if ('' === $content) {
            return Subscription::INTERVAL_MONTH;
        }
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return Subscription::INTERVAL_MONTH;
        }
        $interval = $data['interval'] ?? null;

        return Subscription::INTERVAL_YEAR === $interval ? Subscription::INTERVAL_YEAR : Subscription::INTERVAL_MONTH;
    }

    /**
     * Resolve the space and assert the caller is an admin (or global admin).
     * Returns a JsonResponse to short-circuit on any failure (401/403/404),
     * mirroring the existence-hiding shape of {@see SpaceExportController}.
     */
    private function resolveAdminSpace(string $id, ?User $user): Space|JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $space = $this->em->getRepository(Space::class)->find($id);
        if (null === $space) {
            return $this->json(['error' => 'Space not found.'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$space->isAdmin($user)) {
            if (!$space->hasMember($user)) {
                return $this->json(['error' => 'Space not found.'], 404);
            }
            return $this->json(['error' => 'Only space admins can manage billing.'], 403);
        }

        return $space;
    }

    /**
     * Safely walk a nested key path on a decoded webhook payload.
     *
     * @param list<string|int> $path
     */
    private function dig(mixed $data, array $path): mixed
    {
        $cursor = $data;
        foreach ($path as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return null;
            }
            $cursor = $cursor[$key];
        }

        return $cursor;
    }

    /**
     * {@see dig()} narrowed to a non-empty string, else null.
     *
     * @param list<string|int> $path
     */
    private function stringAt(mixed $data, array $path): ?string
    {
        $value = $this->dig($data, $path);

        return is_string($value) && '' !== $value ? $value : null;
    }
}
