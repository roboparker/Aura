<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserSessionRepository;
use App\Service\SensitiveActionVerifier;
use App\Service\UserAgentSummarizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Active-session management for the current user (Settings → Security).
 * Sessions are tracked by {@see \App\EventListener\SessionRegistryListener};
 * this controller lists them and revokes them. "Sign out everywhere else" is
 * step-up protected via {@see SensitiveActionVerifier} — a stolen cookie
 * shouldn't be able to silently boot the legitimate user's other devices.
 */
class UserSessionController extends AbstractController
{
    use JsonRequestTrait;

    public function __construct(
        private readonly UserSessionRepository $sessions,
        private readonly EntityManagerInterface $em,
        private readonly UserAgentSummarizer $userAgents,
        private readonly SensitiveActionVerifier $verifier,
    ) {
    }

    #[Route('/me/sessions', name: 'me_sessions_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $currentHash = $this->currentHash($request);
        $rows = array_map(
            fn ($s): array => [
                'id' => (string) $s->getId(),
                'current' => '' !== $currentHash && $s->getSessionIdHash() === $currentHash,
                'device' => $this->userAgents->summarize($s->getUserAgent()),
                'ipAddress' => $s->getIpAddress(),
                'createdAt' => $s->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'lastSeenAt' => $s->getLastSeenAt()->format(\DateTimeInterface::ATOM),
            ],
            $this->sessions->findActiveForUser($user),
        );

        return $this->json(['sessions' => $rows]);
    }

    #[Route('/me/sessions/{id}', name: 'me_sessions_revoke', methods: ['DELETE'])]
    public function revoke(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Not found.'], 404);
        }

        $session = $this->sessions->find($id);
        if (null === $session || true !== $session->getUser()?->getId()?->equals($user->getId())) {
            return $this->json(['error' => 'Not found.'], 404);
        }

        $session->revoke();
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    #[Route('/me/sessions/revoke-others', name: 'me_sessions_revoke_others', methods: ['POST'], priority: 10)]
    public function revokeOthers(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $err = $this->verifier->verify($user, $this->jsonBody($request));
        if (null !== $err) {
            return $this->json(['error' => $err[1]], $err[0]);
        }

        $currentHash = $this->currentHash($request);
        $revoked = $this->sessions->revokeOthers($user, $currentHash);

        return $this->json(['revoked' => $revoked]);
    }

    private function currentHash(Request $request): string
    {
        if (!$request->hasSession()) {
            return '';
        }
        $id = $request->getSession()->getId();
        return '' === $id ? '' : hash('sha256', $id);
    }
}
