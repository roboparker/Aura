<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\CalendarFeedTokenManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Self-service management of the current user's read-only iCal calendar feed
 * (#calendar-sync). The feed itself is served token-authenticated from
 * {@see CalendarFeedController}; these routes are session-gated (ROLE_USER) so
 * only the signed-in owner can reveal status, (re)generate, or revoke it.
 *
 * Because the token is stored hashed, the plaintext subscribe URL is returned
 * exactly once — from the regenerate endpoint — and never again. `GET` reports
 * only metadata (enabled / created / last-synced); the PWA keeps the one-shot
 * URL in view after generation and otherwise offers "regenerate" to mint a new
 * one.
 */
class CalendarFeedSettingsController extends AbstractController
{
    public function __construct(
        private readonly CalendarFeedTokenManager $tokens,
    ) {
    }

    #[Route('/me/calendar-feed', name: 'me_calendar_feed_get', methods: ['GET'])]
    public function status(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $token = $this->tokens->findForUser($user);

        return $this->json([
            'enabled' => null !== $token,
            'createdAt' => $token?->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'lastAccessedAt' => $token?->getLastAccessedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/me/calendar-feed/regenerate', name: 'me_calendar_feed_regenerate', methods: ['POST'])]
    public function regenerate(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $plaintext = $this->tokens->regenerate($user);
        $feedUrl = $this->tokens->buildFeedUrl($request->getSchemeAndHttpHost(), $plaintext);

        return $this->json(['feedUrl' => $feedUrl], 201);
    }

    #[Route('/me/calendar-feed', name: 'me_calendar_feed_delete', methods: ['DELETE'])]
    public function revoke(#[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $this->tokens->revoke($user);

        return new JsonResponse(null, 204);
    }
}
