<?php

namespace App\Controller;

use App\Entity\Board;
use App\Entity\Space;
use App\Entity\User;
use App\Service\SpaceIriResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Moves a board into a different space (#182).
 *
 * Auth bar: caller must be a member of the board's CURRENT space
 * (so they can already write to it) AND of the TARGET space (so
 * they're authorised to drop content there). Same shape as the
 * board edit/delete predicates plus a target-membership check.
 *
 * Tasks don't carry a denormalised `space` FK — their access is
 * derived through `task.board.space`, so updating the board is
 * enough. Custom fields are space-owned now (#custom-fields-space) and
 * shared across a space's boards, so the board just picks up the
 * target space's fields. Discussions and pages are space-owned too and
 * aren't touched by this move.
 *
 * Audit history is preserved automatically: Board is `Loggable`,
 * so the `space` change lands as a regular update entry.
 */
class BoardMoveController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/boards/{id}/move', name: 'board_move', methods: ['POST'])]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Board not found.'], 404);
        }

        $board = $this->em->getRepository(Board::class)->find($id);
        if (null === $board) {
            return $this->json(['error' => 'Board not found.'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$board->isAccessibleBy($user)) {
            // Hide source-membership failures behind a 404 to match the
            // access-extension's existence-hiding shape.
            return $this->json(['error' => 'Board not found.'], 404);
        }

        $payload = $request->toArray();
        $rawSpace = $payload['space'] ?? null;
        if (!is_string($rawSpace) || '' === trim($rawSpace)) {
            return $this->json(['error' => 'Target `space` IRI is required.'], 400);
        }
        $spaceId = SpaceIriResolver::extractId($rawSpace);
        if (null === $spaceId) {
            return $this->json(['error' => 'Invalid space IRI.'], 400);
        }

        $target = $this->em->getRepository(Space::class)->find($spaceId);
        if (null === $target) {
            return $this->json(['error' => 'Target space not found.'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$target->hasMember($user)) {
            // Hide target existence to mirror SpaceAccessExtension.
            return $this->json(['error' => 'Target space not found.'], 404);
        }

        $current = $board->getSpace();
        if (null !== $current && true === $current->getId()?->equals($target->getId())) {
            // No-op move — return the board unchanged with 200 rather
            // than a 304 so the PWA's "Move to" UX gets a consistent
            // response shape even if the user picks the current space.
            return $this->json([
                '@id' => '/boards/' . $board->getId(),
                'id' => (string) $board->getId(),
                'space' => '/spaces/' . $target->getId(),
                'moved' => false,
            ], 200);
        }

        $board->setSpace($target);

        // Custom fields are space-owned now (#custom-fields-space) and
        // per-board shown: the board's selected fields belong to the SOURCE
        // space, so detach them on move — the target space's own fields can be
        // opted into afterwards. Discussions/pages are space-owned, not moved.
        $board->getCustomFieldDefinitions()->clear();

        $this->em->flush();

        return $this->json([
            '@id' => '/boards/' . $board->getId(),
            'id' => (string) $board->getId(),
            'space' => '/spaces/' . $target->getId(),
            'moved' => true,
        ], 200);
    }
}
