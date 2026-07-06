<?php

declare(strict_types=1);

namespace App\Controller;

use App\Billing\BillingException;
use App\Billing\Plan;
use App\Billing\PlanGate;
use App\Billing\StripeGatewayInterface;
use App\Entity\CancellationFeedback;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Service\CancellationFeedbackRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Billing for a **personal** account (#billing Phase 1b) — the current user's
 * own plan (Free → Pro), covering the spaces they own personally. Individual,
 * so the seat quantity is always 1. Same Stripe hosted-page flow as the org
 * controller; the account is the signed-in user.
 */
class MeBillingController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private StripeGatewayInterface $stripe,
        private SubscriptionRepository $subscriptions,
        private PlanGate $planGate,
        private CancellationFeedbackRecorder $feedback,
        private LoggerInterface $logger,
        #[Autowire('%env(string:default::STRIPE_PRICE_PRO_MONTHLY)%')]
        private string $proMonthly,
        #[Autowire('%env(string:default::STRIPE_PRICE_PRO_YEARLY)%')]
        private string $proYearly,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    #[Route('/me/billing', name: 'me_billing_status', methods: ['GET'])]
    public function status(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $subscription = $this->subscriptions->findActiveForUser($user);
        $entitlements = $this->planGate->personalEntitlements($user);

        return $this->json([
            'plan' => $entitlements->plan->value,
            'planLabel' => $entitlements->plan->label(),
            'status' => $subscription?->getStatus(),
            'active' => null !== $subscription,
            'billingAvailable' => $this->stripe->isConfigured(),
            'billingInterval' => $subscription?->getBillingInterval(),
            'currentPeriodEnd' => $subscription?->getCurrentPeriodEnd()?->format(\DateTimeInterface::ATOM),
            'cancelAtPeriodEnd' => $subscription?->getCancelAtPeriodEnd() ?? false,
            'features' => $entitlements->features,
            'limits' => $entitlements->limits,
        ]);
    }

    #[Route('/me/billing/checkout', name: 'me_billing_checkout', methods: ['POST'])]
    public function checkout(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!$this->stripe->isConfigured()) {
            return $this->json(['error' => 'Billing is not available on this instance.'], 503);
        }

        $interval = $this->readInterval($request);
        $priceId = Subscription::INTERVAL_YEAR === $interval ? $this->proYearly : $this->proMonthly;
        if ('' === $priceId) {
            return $this->json(['error' => 'The Pro plan is not configured.'], 503);
        }

        $customerId = $this->subscriptions->findActiveForUser($user)?->getStripeCustomerId();

        try {
            $url = $this->stripe->createCheckoutSession(
                priceId: $priceId,
                quantity: 1,
                successUrl: $this->frontendUrl . '/settings/billing?billing=success',
                cancelUrl: $this->frontendUrl . '/settings/billing?billing=cancelled',
                customerId: $customerId,
                customerEmail: null !== $customerId ? null : $user->getEmail(),
                metadata: ['user_id' => (string) $user->getId(), 'plan' => Plan::Pro->value],
            );
        } catch (BillingException $e) {
            $this->logger->error('Personal checkout failed', ['exception' => $e, 'user' => (string) $user->getId()]);

            return $this->json(['error' => 'Could not start checkout. Please try again.'], 502);
        }

        return $this->json(['url' => $url]);
    }

    #[Route('/me/billing/portal', name: 'me_billing_portal', methods: ['POST'])]
    public function portal(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $customerId = $this->subscriptions->findActiveForUser($user)?->getStripeCustomerId();
        if (null === $customerId) {
            return $this->json(['error' => 'You have no billing to manage.'], 409);
        }
        if (!$this->stripe->isConfigured()) {
            return $this->json(['error' => 'Billing is not available on this instance.'], 503);
        }

        try {
            $url = $this->stripe->createBillingPortalSession($customerId, $this->frontendUrl . '/settings/billing');
        } catch (BillingException $e) {
            $this->logger->error('Personal portal failed', ['exception' => $e, 'user' => (string) $user->getId()]);

            return $this->json(['error' => 'Could not open the billing portal.'], 502);
        }

        return $this->json(['url' => $url]);
    }

    #[Route('/me/billing/cancel', name: 'me_billing_cancel', methods: ['POST'])]
    public function cancel(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $subscription = $this->subscriptions->findActiveForUser($user);
        $stripeSubId = $subscription?->getStripeSubscriptionId();
        if (null === $subscription || null === $stripeSubId) {
            return $this->json(['error' => 'No active subscription to cancel.'], 409);
        }
        if ($subscription->getCancelAtPeriodEnd()) {
            return $this->json(['error' => 'This subscription is already set to cancel.'], 409);
        }
        if (!$this->stripe->isConfigured()) {
            return $this->json(['error' => 'Billing is not available on this instance.'], 503);
        }

        $body = $this->readBody($request);
        $reasonError = $this->feedback->reasonError($body);
        if (null !== $reasonError) {
            return $this->json(['error' => $reasonError], 422);
        }

        try {
            $this->stripe->cancelSubscriptionAtPeriodEnd($stripeSubId);
        } catch (BillingException $e) {
            $this->logger->error('Personal cancel failed', ['exception' => $e, 'user' => (string) $user->getId()]);

            return $this->json(['error' => 'Could not cancel the subscription.'], 502);
        }

        $this->feedback->record(CancellationFeedback::CONTEXT_SUBSCRIPTION_CANCELLATION, $user, null, $body);
        $subscription->setCancelAtPeriodEnd(true)->touch();
        $this->em->flush();

        return $this->json(['ok' => true, 'cancelAtPeriodEnd' => true]);
    }

    private function readInterval(Request $request): string
    {
        $data = $this->readBody($request);

        return Subscription::INTERVAL_YEAR === ($data['interval'] ?? null)
            ? Subscription::INTERVAL_YEAR
            : Subscription::INTERVAL_MONTH;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function readBody(Request $request): array
    {
        if ('' === $request->getContent()) {
            return [];
        }
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }
}
