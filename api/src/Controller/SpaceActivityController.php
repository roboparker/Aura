<?php

namespace App\Controller;

use App\Entity\Page;
use App\Entity\Board;
use App\Entity\Space;
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
 * Space-level activity feed for the dashboard. Aggregates the audit
 * history of every Loggable entity that lives in the space — its
 * boards, its pages, and the tasks inside those boards — into one
 * chronological stream, reusing {@see ActivityFeedQuery} so the shape
 * matches `/boards/{id}/activity` and `/tasks/{id}/activity`.
 *
 * Access mirrors the Space GET visibility: any space member can read;
 * everyone else gets a 404 so the endpoint never leaks a space's
 * existence. Discussions and comments aren't Loggable yet, so they
 * don't appear in the feed — a future enhancement.
 */
class SpaceActivityController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ActivityFeedQuery $activityFeed,
        private BoardActivityScope $scope,
    ) {
    }

    #[Route('/spaces/{id}/activity', name: 'space_activity', methods: ['GET'])]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $space = $this->em->getRepository(Space::class)->find($id);
        if (null === $space || !$this->canRead($space, $user)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        // Roll the per-board activity groups (board + tasks + custom-field
        // definitions, including deleted children) up across every board in
        // the space, then add the space's pages. Reusing BoardActivityScope
        // keeps the hierarchy consistent: a space sees everything its boards
        // see, one level down — the same way space membership cascades.
        $boards = $this->em->getRepository(Board::class)->findBy(['space' => $space]);
        $pages = $this->em->getRepository(Page::class)->findBy(['space' => $space]);

        /** @var array<class-string, list<string>> $groups */
        $groups = [Page::class => array_values(array_map(
            static fn (Page $p): string => (string) $p->getId(),
            $pages,
        ))];
        foreach ($boards as $board) {
            foreach ($this->scope->groupsForProject($board) as $class => $ids) {
                $groups[$class] = array_values(array_unique([
                    ...($groups[$class] ?? []),
                    ...$ids,
                ]));
            }
        }

        return new JsonResponse($this->activityFeed->forObjectGroups($groups, $request));
    }

    private function canRead(Space $space, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        return $space->hasMember($user);
    }
}
