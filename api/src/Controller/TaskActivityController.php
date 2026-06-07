<?php

namespace App\Controller;

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
    public function __construct(
        private EntityManagerInterface $em,
        private ActivityFeedQuery $activityFeed,
    ) {
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

        return new JsonResponse(
            $this->activityFeed->forClass(Task::class, [(string) $task->getId()], $request),
        );
    }

    private function canRead(Task $task, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        return $task->isAccessibleBy($user);
    }
}
