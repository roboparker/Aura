<?php

declare(strict_types=1);

namespace App\Controller;

use App\Billing\BillingException;
use App\Billing\Plan;
use App\Billing\PlanGate;
use App\Billing\StripeGatewayInterface;
use App\Entity\Organization;
use App\Entity\Space;
use App\Entity\CancellationFeedback;
use App\Entity\Invoice;
use App\Entity\InvoicePayment;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Service\CancellationFeedbackRecorder;
use App\Service\SubscriptionReceiptMailer;
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
        private CancellationFeedbackRecorder $feedback,
        private PlanGate $planGate,
        private SubscriptionReceiptMailer $receiptMailer,
        private LoggerInterface $logger,
        #[Autowire('%env(string:default:app.stripe_price_pro_monthly:STRIPE_PRICE_PRO_MONTHLY)%')]
        private string $proMonthly,
        #[Autowire('%env(string:default:app.stripe_price_pro_yearly:STRIPE_PRICE_PRO_YEARLY)%')]
        private string $proYearly,
        #[Autowire('%env(string:default:app.stripe_price_business_monthly:STRIPE_PRICE_BUSINESS_MONTHLY)%')]
        private string $businessMonthly,
        #[Autowire('%env(string:default:app.stripe_price_business_yearly:STRIPE_PRICE_BUSINESS_YEARLY)%')]
        private string $businessYearly,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    /**
     * The Stripe price id for a self-serve plan × interval, or '' when that
     * plan isn't configured on this instance. Enterprise is sales-led (no
     * self-serve price).
     */
    private function priceFor(Plan $plan, string $interval): string
    {
        $yearly = Subscription::INTERVAL_YEAR === $interval;

        return match ($plan) {
            Plan::Pro => $yearly ? $this->proYearly : $this->proMonthly,
            Plan::Business => $yearly ? $this->businessYearly : $this->businessMonthly,
            default => '',
        };
    }

    /** Self-serve plan from the request body (pro | business); defaults to Pro. */
    private function readPlan(Request $request): Plan
    {
        $body = $this->readBody($request);

        return 'business' === ($body['plan'] ?? null) ? Plan::Business : Plan::Pro;
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
        $plan = $this->readPlan($request);
        $priceId = $this->priceFor($plan, $interval);
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
                // Stamp the plan so the webhook records which tier was bought.
                metadata: ['space_id' => $id, 'plan' => $plan->value],
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

    /**
     * Cancel the space's subscription at the end of the current period, after
     * recording the required "why are you leaving?" survey. We cancel here (vs.
     * sending the admin to the Stripe portal) so we own the moment the reason
     * is asked. The authoritative state still lands via the webhook; we
     * optimistically flag the mirror row so the UI updates immediately.
     */
    #[Route('/spaces/{id}/billing/cancel', name: 'billing_cancel', methods: ['POST'])]
    public function cancel(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $space = $this->resolveAdminSpace($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }

        $subscription = $this->subscriptions->findActiveForSpace($space);
        $stripeSubId = $subscription?->getStripeSubscriptionId();
        if (null === $subscription || null === $stripeSubId) {
            return $this->json(['error' => 'This space has no active subscription to cancel.'], 409);
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
            $this->logger->error('Stripe subscription cancel failed', ['exception' => $e, 'space' => $id]);
            return $this->json(['error' => 'Could not cancel the subscription. Please try again.'], 502);
        }

        // Record the survey only after Stripe accepted the cancellation, so a
        // failed cancel never leaves orphan feedback behind.
        $this->feedback->record(
            CancellationFeedback::CONTEXT_SUBSCRIPTION_CANCELLATION,
            $user,
            $space,
            $body,
        );

        // Optimistic local mirror — the webhook will confirm/refine this.
        $subscription->setCancelAtPeriodEnd(true)->touch();
        $this->em->flush();

        return $this->json([
            'ok' => true,
            'cancelAtPeriodEnd' => true,
            'currentPeriodEnd' => $subscription->getCurrentPeriodEnd()?->format(\DateTimeInterface::ATOM),
        ]);
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
        $entitlements = $this->planGate->spaceEntitlements($space);

        return $this->json([
            'plan' => $entitlements->plan->value,
            'planLabel' => $entitlements->plan->label(),
            'status' => $subscription?->getStatus(),
            'active' => null !== $subscription,
            'billingAvailable' => $this->stripe->isConfigured(),
            'seats' => $subscription?->getSeats(),
            'billingInterval' => $subscription?->getBillingInterval(),
            'currentPeriodEnd' => $subscription?->getCurrentPeriodEnd()?->format(\DateTimeInterface::ATOM),
            'cancelAtPeriodEnd' => $subscription?->getCancelAtPeriodEnd() ?? false,
            'isAdmin' => $space->isAdmin($user) || $this->isGranted('ROLE_ADMIN'),
            // The full entitlement matrix for this space's plan — drives the
            // upgrade/feature-gating UI.
            'features' => $entitlements->features,
            'limits' => $entitlements->limits + [
                'memberCount' => \count($space->getEffectiveUsers()),
                // Back-compat keys the current PWA card still reads.
                'freeSpaceMemberLimit' => $this->usageLimiter->freeSpaceMemberLimit(),
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
        } elseif ('checkout.session.completed' === $type) {
            $this->markInvoicePaid($object);
        } elseif ('invoice.payment_succeeded' === $type) {
            $this->sendSubscriptionReceipt($object);
        } elseif ('account.updated' === $type) {
            $this->syncConnectAccount($object);
        }
        // Any other event type is acknowledged and ignored.

        return new Response('', Response::HTTP_OK);
    }

    /**
     * Email a payment receipt off Stripe's `invoice.payment_succeeded` (fired
     * for the first checkout charge and every renewal). Only subscription
     * invoices — one-off client-invoice payments (#445) go through
     * checkout.session.completed above. Best-effort: a mail failure is logged
     * but still 200s so Stripe doesn't retry the whole event.
     *
     * @param array<mixed, mixed> $invoice
     */
    private function sendSubscriptionReceipt(array $invoice): void
    {
        $stripeSubId = $this->stringAt($invoice, ['subscription']);
        if (null === $stripeSubId) {
            return; // Not a subscription invoice.
        }

        $amountPaid = $this->dig($invoice, ['amount_paid']);
        if (!is_int($amountPaid) || $amountPaid <= 0) {
            return; // Nothing charged (100%-off coupon / trial) — no receipt.
        }

        $recipient = $this->stringAt($invoice, ['customer_email'])
            ?? $this->receiptRecipientFor($stripeSubId);
        if (null === $recipient) {
            $this->logger->warning('Subscription receipt: no recipient email resolved.', ['subscription' => $stripeSubId]);
            return;
        }

        try {
            $this->receiptMailer->sendReceipt(
                $recipient,
                $amountPaid,
                $this->stringAt($invoice, ['currency']) ?? 'usd',
                $this->stringAt($invoice, ['number']),
                $this->stringAt($invoice, ['hosted_invoice_url']),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to send subscription receipt: {error}', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Resolve the billing account's email for a subscription id when the Stripe
     * invoice didn't carry `customer_email`. Personal accounts only — org/space
     * invoices reliably include customer_email, so a null here just skips.
     */
    private function receiptRecipientFor(string $stripeSubId): ?string
    {
        $owner = $this->subscriptions->findByStripeSubscriptionId($stripeSubId)?->getOwnerUser();

        return $owner instanceof User ? $owner->getEmail() : null;
    }

    /**
     * Mark one of our client invoices (#445) paid off a completed one-off
     * Checkout Session. Idempotent + safe: only acts on sessions carrying our
     * `invoice_id` metadata with payment_status=paid, and never resurrects a
     * void invoice.
     *
     * @param array<mixed, mixed> $session
     */
    private function markInvoicePaid(array $session): void
    {
        $invoiceId = $this->stringAt($session, ['metadata', 'invoice_id']);
        if (null === $invoiceId) {
            return; // Not an invoice checkout (e.g. a subscription session).
        }
        if ('paid' !== $this->stringAt($session, ['payment_status'])) {
            return;
        }
        $invoice = $this->em->getRepository(Invoice::class)->find($invoiceId);
        if (!$invoice instanceof Invoice) {
            $this->logger->warning('Invoice payment webhook could not resolve invoice.', ['invoice_id' => $invoiceId]);
            return;
        }
        if (Invoice::STATUS_PAID === $invoice->getStatus() || Invoice::STATUS_VOID === $invoice->getStatus()) {
            return;
        }

        // Record the payment on the ledger (#648): the session charged the
        // balance due at checkout time; fall back to the current balance if
        // Stripe's amount_total is missing. Paid only when the balance clears.
        $amountTotal = $this->dig($session, ['amount_total']);
        $amount = is_int($amountTotal) && $amountTotal > 0 ? $amountTotal : $invoice->getBalanceDue();
        if ($amount > 0) {
            $invoice->addPayment(
                (new InvoicePayment())
                    ->setAmount(min($amount, $invoice->getBalanceDue()))
                    ->setMethod(InvoicePayment::METHOD_STRIPE),
            );
        }
        if (0 === $invoice->getBalanceDue()) {
            $invoice->setStatus(Invoice::STATUS_PAID);
            $invoice->setPaidAt(new \DateTimeImmutable());
        }
        $this->em->flush();
    }

    /**
     * Mirror a connected account's onboarding state onto its space (#connect)
     * off `account.updated`. Best-effort instant sync — the status endpoint's
     * on-demand refresh is the reliable path, so an unresolved account here is
     * just ignored.
     *
     * @param array<mixed, mixed> $account
     */
    private function syncConnectAccount(array $account): void
    {
        $accountId = $this->stringAt($account, ['id']);
        if (null === $accountId) {
            return;
        }
        $space = $this->em->getRepository(Space::class)->findOneBy(['stripeConnectAccountId' => $accountId]);
        if (!$space instanceof Space) {
            return;
        }
        $space->setStripeConnectChargesEnabled(true === $this->dig($account, ['charges_enabled']));
        $space->setStripeConnectDetailsSubmitted(true === $this->dig($account, ['details_submitted']));
        $this->em->flush();
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
            // Resolve the billing account: an organization (#billing Phase 1b),
            // falling back to the legacy per-space id.
            $orgId = $this->stringAt($sub, ['metadata', 'organization_id']);
            $userId = $this->stringAt($sub, ['metadata', 'user_id']);
            $spaceId = $this->stringAt($sub, ['metadata', 'space_id']);
            $row = (new Subscription())->setStripeSubscriptionId($stripeSubId);
            if (null !== $orgId) {
                $org = $this->em->getRepository(Organization::class)->find($orgId);
                if (!$org instanceof Organization) {
                    $this->logger->warning('Stripe webhook could not resolve organization.', ['subscription' => $stripeSubId, 'organization_id' => $orgId]);
                    return;
                }
                $row->setOrganization($org);
            } elseif (null !== $userId) {
                $account = $this->em->getRepository(User::class)->find($userId);
                if (!$account instanceof User) {
                    $this->logger->warning('Stripe webhook could not resolve personal account.', ['subscription' => $stripeSubId, 'user_id' => $userId]);
                    return;
                }
                $row->setOwnerUser($account);
            } elseif (null !== $spaceId) {
                $space = $this->em->getRepository(Space::class)->find($spaceId);
                if (!$space instanceof Space) {
                    $this->logger->warning('Stripe webhook could not resolve space.', ['subscription' => $stripeSubId, 'space_id' => $spaceId]);
                    return;
                }
                $row->setSpace($space);
            } else {
                $this->logger->warning('Stripe webhook carried no billing account.', ['subscription' => $stripeSubId]);
                return;
            }
            $this->em->persist($row);
        }

        $status = $deleted ? Subscription::STATUS_CANCELED : ($this->stringAt($sub, ['status']) ?? Subscription::STATUS_INCOMPLETE);
        $row->setStatus($status);

        // The tier bought, carried on the subscription metadata from checkout.
        $planMeta = $this->stringAt($sub, ['metadata', 'plan']);
        if (null !== $planMeta) {
            $row->setPlan($planMeta);
        }

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
     * Decode the JSON request body to an associative array, returning [] for an
     * empty or malformed body.
     *
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
