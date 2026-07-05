<?php

namespace App\Controller;

use App\Entity\Task;
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
 * `GET /tasks/{id}/relationships` — every relationship touching the task, on
 * either side, flattened into the task's own point of view: each row carries
 * the direction-aware label and a summary of the *other* task. Access mirrors
 * the Task GET expression (admin / owner / board-space member); 404 for
 * unreachable ids, matching the rest of the API.
 */
class TaskRelationshipController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TaskRelationshipRepository $relationships,
    ) {
    }

    #[Route('/tasks/{id}/relationships', name: 'task_relationships', methods: ['GET'])]
    public function __invoke(string $id, #[CurrentUser] ?User $user): Response
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

        $taskId = (string) $task->getId();
        $items = [];
        foreach ($this->relationships->findForTask($task) as $relationship) {
            $source = $relationship->getSource();
            $target = $relationship->getTarget();
            if (null === $source || null === $target) {
                continue;
            }
            $outgoing = (string) $source->getId() === $taskId;
            $other = $outgoing ? $target : $source;

            $items[] = [
                '@id' => '/task_relationships/' . $relationship->getId(),
                'id' => (string) $relationship->getId(),
                'type' => $relationship->getType(),
                'direction' => $outgoing ? 'outgoing' : 'incoming',
                'label' => TaskRelationship::labelFor($relationship->getType(), $outgoing),
                'task' => [
                    '@id' => $other->getId() !== null ? '/tasks/' . $other->getId() : null,
                    'id' => (string) $other->getId(),
                    'title' => $other->getTitle(),
                    'completedOn' => $other->getCompletedOn()?->format(\DATE_ATOM),
                    'dueDate' => $other->getDueDate()?->format(\DATE_ATOM),
                ],
            ];
        }

        return new JsonResponse(['relationships' => $items]);
    }

    private function canRead(Task $task, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return $task->isAccessibleBy($user);
    }
}
