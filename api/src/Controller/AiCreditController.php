<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Space;
use App\Entity\User;
use App\Service\AgentChatService;
use App\Service\AiCreditMeter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * What an account has left of its monthly AI allowance (#827).
 *
 * Addressed by **space** even though credits pool at the organization, because
 * a space is what the PWA has in hand everywhere agents are managed, and
 * resolving the owning account here saves every caller from knowing the account
 * model. The payload says which account and period it is reporting so the
 * pooling is visible rather than surprising when two spaces show the same
 * numbers.
 *
 * Readable by **any space member**, not just admins: someone about to talk to
 * an agent needs to know whether it can answer, and the numbers are aggregate
 * usage rather than anything sensitive. Non-members get a 404, matching the
 * rest of the space API.
 */
final class AiCreditController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AiCreditMeter $meter,
        private readonly AgentChatService $chat,
    ) {
    }

    #[Route('/spaces/{id}/ai-credits', name: 'space_ai_credits', methods: ['GET'])]
    public function show(string $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Space not found.'], 404);
        }
        $space = $this->em->getRepository(Space::class)->find($id);
        if (null === $space) {
            return $this->json(['error' => 'Space not found.'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user)) {
            return $this->json(['error' => 'Space not found.'], 404);
        }

        $organization = $space->getOrganization();
        if (null === $organization) {
            // Phase 2 made `space.organization` NOT NULL, so this is defensive
            // rather than expected — but reporting an allowance we can't
            // attribute to an account would be worse than saying we can't.
            return $this->json(['error' => 'This space is not owned by an account.'], 409);
        }

        $unavailable = $this->chat->unavailableReason($space);

        return $this->json([
            ...$this->meter->balance($organization)->toArray(),
            'organization' => [
                'id' => (string) $organization->getId(),
                'name' => $organization->getName(),
            ],
            // Null when agents can answer. Otherwise a stable machine key plus
            // the sentence to show — an upgrade prompt and "the operator hasn't
            // set this up" are very different messages.
            'unavailableReason' => $unavailable?->reason,
            'unavailableMessage' => $unavailable?->getMessage(),
        ]);
    }
}
