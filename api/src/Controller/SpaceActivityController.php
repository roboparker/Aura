<?php

namespace App\Controller;

use App\Entity\ActivityLog;
use App\Entity\Page;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
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
 * chronological stream, reusing {@see ActivityFeedSerializer} so the
 * shape matches `/projects/{id}/activity` and `/tasks/{id}/activity`.
 *
 * Access mirrors the Space GET visibility: any space member can read;
 * everyone else gets a 404 so the endpoint never leaks a space's
 * existence. Discussions and comments aren't Loggable yet, so they
 * don't appear in the feed — a future enhancement.
 */
class SpaceActivityController extends AbstractController
{
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 100;

    public function __construct(private EntityManagerInterface $em)
    {
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

        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = min(
            self::MAX_PAGE_SIZE,
            max(1, (int) $request->query->get('itemsPerPage', (string) self::DEFAULT_PAGE_SIZE)),
        );

        // Resolve the id sets that scope the feed to this space. Tasks
        // are reached through their project (a space has no direct task
        // collection), mirroring ProjectActivityController.
        $projects = $this->em->getRepository(Project::class)->findBy(['space' => $space]);
        $pages = $this->em->getRepository(Page::class)->findBy(['space' => $space]);
        $projectIds = array_map(static fn (Project $p): string => (string) $p->getId(), $projects);
        $pageIds = array_map(static fn (Page $p): string => (string) $p->getId(), $pages);
        $taskIds = [];
        foreach ($projects as $project) {
            foreach ($project->getTasks() as $task) {
                $taskIds[] = (string) $task->getId();
            }
        }

        // Build the OR-of-class clauses for whatever id sets exist. A
        // brand-new space with no content yields an empty feed.
        $clauses = [];
        /** @var array<string, mixed> $params */
        $params = [];
        if ([] !== $projectIds) {
            $clauses[] = '(l.objectClass = :projectClass AND l.objectId IN (:projectIds))';
            $params['projectClass'] = Project::class;
            $params['projectIds'] = $projectIds;
        }
        if ([] !== $pageIds) {
            $clauses[] = '(l.objectClass = :pageClass AND l.objectId IN (:pageIds))';
            $params['pageClass'] = Page::class;
            $params['pageIds'] = $pageIds;
        }
        if ([] !== $taskIds) {
            $clauses[] = '(l.objectClass = :taskClass AND l.objectId IN (:taskIds))';
            $params['taskClass'] = Task::class;
            $params['taskIds'] = $taskIds;
        }

        if ([] === $clauses) {
            return new JsonResponse(
                ActivityFeedSerializer::serialize([], 0, $page, $perPage, $this->em),
            );
        }

        $where = implode(' OR ', $clauses);
        $repo = $this->em->getRepository(ActivityLog::class);

        $applyParams = static function (QueryBuilder $qb) use ($params): QueryBuilder {
            foreach ($params as $key => $value) {
                $qb->setParameter($key, $value);
            }
            return $qb;
        };

        // Count must NOT carry ORDER BY — Postgres rejects mixing it
        // with COUNT(*).
        $rawTotal = $applyParams(
            $repo->createQueryBuilder('l')->select('COUNT(l.id)')->where($where),
        )->getQuery()->getSingleScalarResult();
        $totalItems = is_numeric($rawTotal) ? (int) $rawTotal : 0;

        /** @var ActivityLog[] $rows */
        $rows = $applyParams(
            $repo->createQueryBuilder('l')
                ->where($where)
                ->orderBy('l.loggedAt', 'DESC')
                ->addOrderBy('l.version', 'DESC')
                ->setFirstResult(($page - 1) * $perPage)
                ->setMaxResults($perPage),
        )->getQuery()->getResult();

        return new JsonResponse(
            ActivityFeedSerializer::serialize($rows, $totalItems, $page, $perPage, $this->em),
        );
    }

    private function canRead(Space $space, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        return $space->hasMember($user);
    }
}
