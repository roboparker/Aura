<?php

declare(strict_types=1);

namespace App\Controller;

use App\Billing\BillingException;
use App\Billing\Plan;
use App\Billing\PlanGate;
use App\Billing\StripeGatewayInterface;
use App\Entity\CancellationFeedback;
use App\Entity\Organization;
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
 * Billing for an {@see Organization} account (#billing Phase 1b) — the team
 * plans (Business self-serve; Enterprise is sales-led). Mirrors the Stripe
 * hosted-page flow of the space billing controller, but the billable unit is
 * the org and the seat quantity is its non-guest member count.
 */
class OrganizationBillingController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private StripeGatewayInterface $stripe,
        private SubscriptionRepository $subscriptions,
        private PlanGate $planGate,
        private CancellationFeedbackRecorder $feedback,
        private LoggerInterface $logger,
        #[Autowire('%env(string:default:app.stripe_price_business_monthly:STRIPE_PRICE_BUSINESS_MONTHLY)%')]
        private string $businessMonthly,
        #[Autowire('%env(string:default:app.stripe_price_business_yearly:STRIPE_PRICE_BUSINESS_YEARLY)%')]
        private string $businessYearly,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    #[Route('/organizations/{id}/billing', name: 'org_billing_status', methods: ['GET'])]
    public function status(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $org = $this->em->getRepository(Organization::class)->find($id);
        if (null === $org || !($this->isGranted('ROLE_ADMIN') || $org->hasMember($user))) {
            return $this->json(['error' => 'Organization not found.'], 404);
        }

        $subscription = $this->subscriptions->findActiveForOrganization($org);
        $entitlements = $this->planGate->organizationEntitlements($org);

        return $this->json([
            'plan' => $entitlements->plan->value,
            'planLabel' => $entitlements->plan->label(),
            'status' => $subscription?->getStatus(),
            'active' => null !== $subscription,
            'billingAvailable' => $this->stripe->isConfigured(),
            'seats' => $subscription?->getSeats(),
            'seatCount' => $org->seatCount(),
            'billingInterval' => $subscription?->getBillingInterval(),
            'currentPeriodEnd' => $subscription?->getCurrentPeriodEnd()?->format(\DateTimeInterface::ATOM),
            'cancelAtPeriodEnd' => $subscription?->getCancelAtPeriodEnd() ?? false,
            'isAdmin' => $org->isAdmin($user) || $this->isGranted('ROLE_ADMIN'),
            'features' => $entitlements->features,
            'limits' => $entitlements->limits,
        ]);
    }

    #[Route('/organizations/{id}/billing/checkout', name: 'org_billing_checkout', methods: ['POST'])]
    public function checkout(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $org = $this->requireAdmin($id, $user);
        if ($org instanceof JsonResponse) {
            return $org;
        }
        if (!$this->stripe->isConfigured()) {
            return $this->json(['error' => 'Billing is not available on this instance.'], 503);
        }

        $interval = $this->readInterval($request);
        $priceId = Subscription::INTERVAL_YEAR === $interval ? $this->businessYearly : $this->businessMonthly;
        if ('' === $priceId) {
            return $this->json(['error' => 'The Business plan is not configured.'], 503);
        }

        $existing = $this->subscriptions->findActiveForOrganization($org);
        $customerId = $existing?->getStripeCustomerId();

        try {
            $url = $this->stripe->createCheckoutSession(
                priceId: $priceId,
                quantity: max(1, $org->seatCount()),
                successUrl: $this->frontendUrl . '/organizations/' . $id . '/settings?billing=success',
                cancelUrl: $this->frontendUrl . '/organizations/' . $id . '/settings?billing=cancelled',
                customerId: $customerId,
                customerEmail: null !== $customerId ? null : $user?->getEmail(),
                metadata: ['organization_id' => $id, 'plan' => Plan::Business->value],
            );
        } catch (BillingException $e) {
            $this->logger->error('Org checkout failed', ['exception' => $e, 'org' => $id]);

            return $this->json(['error' => 'Could not start checkout. Please try again.'], 502);
        }

        return $this->json(['url' => $url]);
    }

    #[Route('/organizations/{id}/billing/portal', name: 'org_billing_portal', methods: ['POST'])]
    public function portal(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $org = $this->requireAdmin($id, $user);
        if ($org instanceof JsonResponse) {
            return $org;
        }
        $customerId = $this->subscriptions->findActiveForOrganization($org)?->getStripeCustomerId();
        if (null === $customerId) {
            return $this->json(['error' => 'This organization has no billing to manage.'], 409);
        }
        if (!$this->stripe->isConfigured()) {
            return $this->json(['error' => 'Billing is not available on this instance.'], 503);
        }

        try {
            $url = $this->stripe->createBillingPortalSession(
                $customerId,
                $this->frontendUrl . '/organizations/' . $id . '/settings',
            );
        } catch (BillingException $e) {
            $this->logger->error('Org portal failed', ['exception' => $e, 'org' => $id]);

            return $this->json(['error' => 'Could not open the billing portal.'], 502);
        }

        return $this->json(['url' => $url]);
    }

    #[Route('/organizations/{id}/billing/cancel', name: 'org_billing_cancel', methods: ['POST'])]
    public function cancel(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $org = $this->requireAdmin($id, $user);
        if ($org instanceof JsonResponse) {
            return $org;
        }
        $subscription = $this->subscriptions->findActiveForOrganization($org);
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
            $this->logger->error('Org cancel failed', ['exception' => $e, 'org' => $id]);

            return $this->json(['error' => 'Could not cancel the subscription.'], 502);
        }

        $this->feedback->record(CancellationFeedback::CONTEXT_SUBSCRIPTION_CANCELLATION, $user, null, $body);
        $subscription->setCancelAtPeriodEnd(true)->touch();
        $this->em->flush();

        return $this->json(['ok' => true, 'cancelAtPeriodEnd' => true]);
    }

    private function requireAdmin(string $id, ?User $user): Organization|JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        $org = $this->em->getRepository(Organization::class)->find($id);
        if (null === $org || !($this->isGranted('ROLE_ADMIN') || $org->hasMember($user))) {
            return $this->json(['error' => 'Organization not found.'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$org->isAdmin($user)) {
            return $this->json(['error' => 'Only organization admins can manage billing.'], 403);
        }

        return $org;
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
