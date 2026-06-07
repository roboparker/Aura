<?php

namespace App\Controller;

use App\Entity\Page;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\Task;
use App\Entity\User;
use App\Service\ActivityFeedQuery;
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
 * projects, its pages, and the tasks inside those projects — into one
 * chronological stream, reusing {@see ActivityFeedQuery} so the shape
 * matches `/projects/{id}/activity` and `/tasks/{id}/activity`.
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

        // Resolve the id sets that scope the feed to this space. Tasks
        // are reached through their project (a space has no direct task
        // collection), mirroring ProjectActivityController.
        $projects = $this->em->getRepository(Project::class)->findBy(['space' => $space]);
        $pages = $this->em->getRepository(Page::class)->findBy(['space' => $space]);
        $projectIds = array_values(array_map(static fn (Project $p): string => (string) $p->getId(), $projects));
        $pageIds = array_values(array_map(static fn (Page $p): string => (string) $p->getId(), $pages));
        $taskIds = [];
        foreach ($projects as $project) {
            foreach ($project->getTasks() as $task) {
                $taskIds[] = (string) $task->getId();
            }
        }

        return new JsonResponse($this->activityFeed->forObjectGroups([
            Project::class => $projectIds,
            Page::class => $pageIds,
            Task::class => $taskIds,
        ], $request));
    }

    private function canRead(Space $space, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        return $space->hasMember($user);
    }
}
