<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\NotificationMercurePublisher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Hands the PWA a `mercureAuthorization` cookie scoped to the caller's
 * own notifications topic (`/users/{id}/notifications`). Unlike the
 * comment token endpoint there's nothing to look up — a user can only
 * ever subscribe to their own notifications — so the topic is derived
 * straight from the authenticated user.
 */
class NotificationsMercureTokenController extends AbstractController
{
    public function __construct(private Authorization $authorization)
    {
    }

    #[Route(
        '/notifications/mercure-token',
        name: 'notifications_mercure_token',
        methods: ['GET'],
        priority: 10,
    )]
    public function __invoke(Request $request, #[CurrentUser] ?User $user): Response
    {
        if (null === $user || null === $user->getId()) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }

        $topic = NotificationMercurePublisher::topicFor((string) $user->getId());
        $this->authorization->setCookie($request, [$topic]);

        return new JsonResponse(['topic' => $topic]);
    }
}
