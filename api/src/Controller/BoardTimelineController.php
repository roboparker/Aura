<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\User;
use App\Repository\TaskRelationshipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /boards/{id}/dependencies` — the `required` dependency edges among a
 * board's own tasks, for the Timeline (#timeline) view's arrows.
 *
 * The rest of the timeline is computed client-side: the board page already
 * loads its tasks with their custom-field values, and a task's bar runs from
 * its configured start field (see `Board::getTimelineStartDefinition`) to its
 * native `dueDate`. Only the cross-task edges need a query, and batching them
 * here avoids an N-per-task fan-out through `GET /tasks/{id}/relationships`.
 */
class BoardTimelineController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TaskRelationshipRepository $relationships,
    ) {
    }

    #[Route('/boards/{id}/dependencies', name: 'board_dependencies', methods: ['GET'])]
    public function __invoke(string $id, #[CurrentUser] ?User $user): Response
    {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $board = $this->em->getRepository(Board::class)->find($id);
        // Existence-hiding 404 for boards the caller can't read, matching the
        // Board Get security expression.
        if (null === $board || (!$this->isGranted('ROLE_ADMIN') && !$board->isAccessibleBy($user))) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        return new JsonResponse(['edges' => $this->relationships->findRequiredEdgesInBoard($board)]);
    }
}
