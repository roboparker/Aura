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

    /**
     * `GET /tasks/{id}/subtasks` — the task's children (the `parent` links where
     * it's the source), with a completion rollup. Separate from the general
     * relationships read because the subtask panel wants an ordered checklist +
     * progress, not the flattened both-directions view.
     */
    #[Route('/tasks/{id}/subtasks', name: 'task_subtasks', methods: ['GET'])]
    public function subtasks(string $id, #[CurrentUser] ?User $user): Response
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

        $items = [];
        $completed = 0;
        foreach ($this->relationships->findChildLinksOf($task) as $link) {
            $child = $link->getTarget();
            if (null === $child) {
                continue;
            }
            $isDone = null !== $child->getCompletedOn();
            if ($isDone) {
                ++$completed;
            }
            $items[] = [
                // The relationship IRI so the panel can unlink without a lookup.
                'relationshipId' => (string) $link->getId(),
                'id' => (string) $child->getId(),
                '@id' => '/tasks/' . $child->getId(),
                'title' => $child->getTitle(),
                'completed' => $isDone,
                'completedOn' => $child->getCompletedOn()?->format(\DATE_ATOM),
                'dueDate' => $child->getDueDate()?->format(\DATE_ATOM),
            ];
        }

        // The parent link (if this task is itself a subtask), so the drawer can
        // show "part of <parent>" without a second request.
        $parentLink = $this->relationships->findParentLinkOf($task);
        $parent = $parentLink?->getSource();

        return new JsonResponse([
            'subtasks' => $items,
            'total' => count($items),
            'completed' => $completed,
            'parent' => null === $parent ? null : [
                'id' => (string) $parent->getId(),
                '@id' => '/tasks/' . $parent->getId(),
                'title' => $parent->getTitle(),
                'completed' => null !== $parent->getCompletedOn(),
            ],
        ]);
    }

    private function canRead(Task $task, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return $task->isAccessibleBy($user);
    }
}
