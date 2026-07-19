<?php

namespace App\Controller;

use App\Entity\Board;
use App\Entity\User;
use App\Service\ActivityFeedQuery;
use App\Service\BoardActivityScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the audit history for a board AND every task and custom-field
 * definition that belongs to it. Access mirrors the Board GET security
 * expression — any board member can read; non-members get a 404.
 *
 * The history feed combines `Board` rows with `Task` and
 * `CustomFieldDefinition` rows (filtered by the board's ids, including
 * deleted ones) and orders them all by `loggedAt` so the PWA sees a single
 * chronological stream.
 */
class BoardActivityController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ActivityFeedQuery $activityFeed,
        private BoardActivityScope $scope,
    ) {
    }

    #[Route(
        '/boards/{id}/activity',
        name: 'board_activity',
        methods: ['GET'],
    )]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $board = $this->em->getRepository(Board::class)->find($id);
        if (null === $board || !$this->canRead($board, $user)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        // The board's audit history rolls up its tasks and custom-field
        // definitions — including deleted children, recovered via the versioned
        // `board` on their log rows. The same grouping feeds the space-level
        // feed one level up, so the hierarchy stays consistent.
        return new JsonResponse(
            $this->activityFeed->forObjectGroups($this->scope->groupsForBoard($board), $request),
        );
    }

    private function canRead(Board $board, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        return $board->isAccessibleBy($user);
    }
}
