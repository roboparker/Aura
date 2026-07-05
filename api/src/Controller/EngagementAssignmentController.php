<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Engagement;
use App\Entity\Board;
use App\Entity\User;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Assign task-management {@see Board}s to a {@see Engagement} from the
 * engagement's page. `PUT /engagements/{id}/boards` replaces the
 * whole assigned set (body `{ boards: [iri|uuid, ...] }`): boards in the
 * list are pointed at this engagement, and any previously-assigned board
 * not in the list is unassigned. Space-admin / invoices-update gated; every
 * board must live in the same space.
 */
class EngagementAssignmentController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SpacePermissionResolver $permissions,
    ) {
    }

    #[Route('/engagements/{id}/boards', name: 'engagement_assign', methods: ['PUT'])]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $engagement = $this->em->getRepository(Engagement::class)->find($id);
        $space = $engagement?->getSpace();
        // Existence-hiding: unknown board / non-members get 404.
        if (null === $engagement || null === $space || !($this->isGranted('ROLE_ADMIN') || $space->hasMember($user))) {
            return $this->json(['error' => 'Engagement not found.'], 404);
        }
        if (
            !$this->isGranted('ROLE_ADMIN')
            && !$space->isAdmin($user)
            && !$this->permissions->canByExplicitGrant($user, $space, SpacePermission::INVOICES, SpacePermission::UPDATE)
        ) {
            return $this->json(['error' => 'You cannot manage this engagement.'], 403);
        }

        $payload = $request->toArray();
        $raw = $payload['boards'] ?? [];
        if (!is_array($raw)) {
            return $this->json(['error' => 'boards must be an array of board IRIs.'], 422);
        }

        $wanted = [];
        foreach ($raw as $entry) {
            if (!is_string($entry)) {
                return $this->json(['error' => 'Each board must be an IRI string.'], 422);
            }
            $uuid = Uuid::isValid($entry) ? $entry : substr($entry, (int) strrpos($entry, '/') + 1);
            if (!Uuid::isValid($uuid)) {
                return $this->json(['error' => sprintf('Invalid board IRI: %s', $entry)], 422);
            }
            $board = $this->em->getRepository(Board::class)->find($uuid);
            if (null === $board || true !== $board->getSpace()?->getId()?->equals($space->getId())) {
                return $this->json(['error' => 'Board not found in this space.'], 404);
            }
            $wanted[(string) $board->getId()] = $board;
        }

        // Unassign boards currently on this engagement but no longer wanted.
        foreach ($engagement->getAssignedProjects() as $current) {
            if (!isset($wanted[(string) $current->getId()])) {
                $current->setEngagement(null);
            }
        }
        // Assign the wanted set (idempotent; steals a board from another engagement).
        foreach ($wanted as $board) {
            $board->setEngagement($engagement);
        }

        $this->em->flush();

        return $this->json([
            'ok' => true,
            'boards' => array_map(static fn (Board $p): string => (string) $p->getId(), array_values($wanted)),
        ]);
    }
}
