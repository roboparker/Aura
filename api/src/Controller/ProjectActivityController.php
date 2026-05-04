<?php

namespace App\Controller;

use App\Entity\ActivityLog;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the audit history for a project AND every task that belongs to
 * it. Access mirrors the Project GET security expression — any project
 * member can read; non-members get a 404.
 *
 * The history feed combines `Project` rows with `Task` rows (filtered
 * by the project's task IDs) and orders both by `loggedAt` so the PWA
 * sees a single chronological stream.
 */
class ProjectActivityController extends AbstractController
{
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 100;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route(
        '/projects/{id}/activity',
        name: 'project_activity',
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

        $project = $this->em->getRepository(Project::class)->find($id);
        if (null === $project || !$this->canRead($project, $user)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = min(
            self::MAX_PAGE_SIZE,
            max(1, (int) $request->query->get('itemsPerPage', (string) self::DEFAULT_PAGE_SIZE)),
        );

        // Collect every task id belonging to this project — we need the
        // full set to scope the Task slice of the audit feed. Using a
        // subquery keeps the work inside the database.
        $taskIds = array_map(
            static fn (Task $t): string => (string) $t->getId(),
            $project->getTasks()->toArray(),
        );

        $repo = $this->em->getRepository(ActivityLog::class);
        $where = '(l.objectClass = :projectClass AND l.objectId = :projectId)';
        if (!empty($taskIds)) {
            $where .= ' OR (l.objectClass = :taskClass AND l.objectId IN (:taskIds))';
        }

        $applyParams = function ($qb) use ($project, $taskIds) {
            $qb->setParameter('projectClass', Project::class)
                ->setParameter('projectId', (string) $project->getId());
            if (!empty($taskIds)) {
                $qb->setParameter('taskClass', Task::class)
                    ->setParameter('taskIds', $taskIds);
            }
            return $qb;
        };

        // Count and select share filters but the count must NOT carry
        // ORDER BY — Postgres rejects mixing it with COUNT(*).
        $totalItems = (int) $applyParams(
            $repo->createQueryBuilder('l')
                ->select('COUNT(l.id)')
                ->where($where),
        )->getQuery()->getSingleScalarResult();
        $rows = $applyParams(
            $repo->createQueryBuilder('l')
                ->where($where)
                ->orderBy('l.loggedAt', 'DESC')
                ->addOrderBy('l.version', 'DESC')
                ->setFirstResult(($page - 1) * $perPage)
                ->setMaxResults($perPage),
        )->getQuery()->getResult();

        return new JsonResponse(ActivityFeedSerializer::serialize($rows, $totalItems, $page, $perPage, $this->em));
    }

    private function canRead(Project $project, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        foreach ($project->getMembers() as $member) {
            if ($member->getId()?->equals($user->getId())) {
                return true;
            }
        }
        return false;
    }
}
