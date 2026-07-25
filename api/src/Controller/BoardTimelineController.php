<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Board;
use App\Entity\Space;
use App\Entity\TaskRelationship;
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

        // `required` drives the dependency arrows + critical path; `parent`
        // lets the chart nest subtask rows under their parent. Both come back
        // in one query, tagged by type, so the client needn't ask twice.
        $edges = $this->relationships->findEdgesInBoard($board, [
            TaskRelationship::TYPE_REQUIRED,
            TaskRelationship::TYPE_PARENT,
        ]);

        return new JsonResponse(['edges' => $edges]);
    }

    /**
     * `GET /spaces/{id}/dependencies` — the same edges across every board in a
     * space, for the cross-board `/timeline` view. Members only; a non-member
     * gets the access extensions' existence-hiding 404.
     */
    #[Route('/spaces/{id}/dependencies', name: 'space_dependencies', methods: ['GET'])]
    public function space(string $id, #[CurrentUser] ?User $user): Response
    {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $space = $this->em->getRepository(Space::class)->find($id);
        if (null === $space || (!$this->isGranted('ROLE_ADMIN') && !$space->hasMember($user))) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $edges = $this->relationships->findEdgesInSpace($space, [
            TaskRelationship::TYPE_REQUIRED,
            TaskRelationship::TYPE_PARENT,
        ]);

        return new JsonResponse(['edges' => $edges]);
    }
}
