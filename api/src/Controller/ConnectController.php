<?php

declare(strict_types=1);

namespace App\Controller;

use App\Billing\BillingException;
use App\Billing\StripeGatewayInterface;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Stripe Connect onboarding for a space (#connect) — how a space owner sets up
 * where their client invoice payments land. We create an Express connected
 * account and hand the admin off to Stripe's hosted onboarding (Account Link);
 * Stripe collects identity + bank/payout details, so we never touch them.
 *
 *  - POST /spaces/{id}/connect/onboard  — admin → a fresh hosted onboarding URL
 *  - GET  /spaces/{id}/connect/status   — member → readiness (refreshed from Stripe)
 *
 * Readiness is mirrored onto the Space (`charges_enabled` / `details_submitted`)
 * both by the status refresh here and, when the endpoint is subscribed to
 * Connect events, by the `account.updated` webhook in {@see BillingController}.
 */
class ConnectController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StripeGatewayInterface $stripe,
        #[Autowire('%env(default::APP_FRONTEND_URL)%')]
        private readonly string $frontendUrl,
    ) {
    }

    #[Route('/spaces/{id}/connect/onboard', name: 'space_connect_onboard', methods: ['POST'])]
    public function onboard(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $space = $this->adminSpaceOr($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }
        if ($space->getIsPersonal()) {
            return $this->json(['error' => 'Personal spaces cannot collect client payments.'], 422);
        }
        if (!$this->stripe->isConfigured()) {
            return $this->json(['error' => 'Payments are not available on this instance.'], 503);
        }

        try {
            $accountId = $space->getStripeConnectAccountId();
            if (null === $accountId) {
                $accountId = $this->stripe->createConnectAccount($user?->getEmail(), null);
                $space->setStripeConnectAccountId($accountId);
                $this->em->flush();
            }

            $base = rtrim($this->frontendUrl, '/') . '/spaces/' . $id . '/settings';
            $url = $this->stripe->createAccountLink(
                $accountId,
                $base . '?connect=refresh',
                $base . '?connect=return',
            );
        } catch (BillingException $e) {
            return $this->json(['error' => 'Could not start onboarding: ' . $e->getMessage()], 502);
        }

        return $this->json(['url' => $url], 201);
    }

    #[Route('/spaces/{id}/connect/status', name: 'space_connect_status', methods: ['GET'])]
    public function status(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        $space = $this->memberSpaceOr($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }

        assert($user instanceof User);
        $isAdmin = $this->isGranted('ROLE_ADMIN') || $space->isAdmin($user);

        $accountId = $space->getStripeConnectAccountId();
        if (null === $accountId) {
            return $this->json([
                'status' => 'none',
                'chargesEnabled' => false,
                'detailsSubmitted' => false,
                'available' => $this->stripe->isConfigured(),
                'isAdmin' => $isAdmin,
            ]);
        }

        // Refresh from Stripe so readiness is accurate even if the webhook
        // hasn't landed (best-effort — a transport error keeps the last known
        // state rather than failing the page).
        if ($this->stripe->isConfigured()) {
            try {
                $account = $this->stripe->retrieveConnectAccount($accountId);
                $space->setStripeConnectChargesEnabled($account['chargesEnabled']);
                $space->setStripeConnectDetailsSubmitted($account['detailsSubmitted']);
                $this->em->flush();
            } catch (BillingException) {
                // Keep the mirrored state.
            }
        }

        return $this->json([
            'status' => $space->canAcceptClientPayments()
                ? 'ready'
                : ($space->isStripeConnectDetailsSubmitted() ? 'pending' : 'incomplete'),
            'chargesEnabled' => $space->isStripeConnectChargesEnabled(),
            'detailsSubmitted' => $space->isStripeConnectDetailsSubmitted(),
            'available' => $this->stripe->isConfigured(),
            'isAdmin' => $isAdmin,
        ]);
    }

    private function adminSpaceOr(string $id, ?User $user): Space|JsonResponse
    {
        $space = $this->memberSpaceOr($id, $user);
        if ($space instanceof JsonResponse) {
            return $space;
        }
        assert($user instanceof User);
        if (!$this->isGranted('ROLE_ADMIN') && !$space->isAdmin($user)) {
            return $this->json(['error' => 'Only space admins can manage payment setup.'], 403);
        }

        return $space;
    }

    private function memberSpaceOr(string $id, ?User $user): Space|JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Space not found.'], 404);
        }
        $space = $this->em->getRepository(Space::class)->find($id);
        if (null === $space || (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user))) {
            return $this->json(['error' => 'Space not found.'], 404);
        }

        return $space;
    }
}
