<?php

namespace App\Controller;

use App\Entity\ActivityLog;
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
 * Reads the audit history for a single task. Access mirrors the Task GET
 * security expression (admin / owner / project member); 404 (not 403)
 * for unreachable IDs to match the rest of the API.
 *
 * Returns paginated `ActivityLog` rows newest-first plus a small actor
 * map so the PWA can render avatars without a second round-trip per
 * row. The controller is deliberately read-only — log entries are
 * written by Gedmo's listener at flush time, never by the API.
 */
class TaskActivityController extends AbstractController
{
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE = 100;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route(
        '/tasks/{id}/activity',
        name: 'task_activity',
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

        $task = $this->em->getRepository(Task::class)->find($id);
        if (null === $task || !$this->canRead($task, $user)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = min(
            self::MAX_PAGE_SIZE,
            max(1, (int) $request->query->get('itemsPerPage', (string) self::DEFAULT_PAGE_SIZE)),
        );

        $repo = $this->em->getRepository(ActivityLog::class);
        // Count and select share filters but the count must NOT carry
        // ORDER BY — Postgres rejects mixing it with COUNT(*).
        $totalItems = (int) $repo->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.objectClass = :class AND l.objectId = :id')
            ->setParameter('class', Task::class)
            ->setParameter('id', (string) $task->getId())
            ->getQuery()
            ->getSingleScalarResult();
        $rows = $repo->createQueryBuilder('l')
            ->where('l.objectClass = :class AND l.objectId = :id')
            ->setParameter('class', Task::class)
            ->setParameter('id', (string) $task->getId())
            ->orderBy('l.loggedAt', 'DESC')
            ->addOrderBy('l.version', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return new JsonResponse(ActivityFeedSerializer::serialize($rows, $totalItems, $page, $perPage, $this->em));
    }

    private function canRead(Task $task, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        if ($task->getOwner()?->getId()?->equals($user->getId())) {
            return true;
        }
        $project = $task->getProject();
        if (null === $project) {
            return false;
        }
        foreach ($project->getMembers() as $member) {
            if ($member->getId()?->equals($user->getId())) {
                return true;
            }
        }
        return false;
    }
}
