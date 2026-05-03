<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Add/remove a single assignee on a task without rewriting the whole list.
 * Mirrors ProjectMemberController's "small targeted endpoint" pattern: the
 * standard PATCH /tasks/{id} can still take a full assignees array, but
 * these endpoints make per-row UI optimistic updates simpler.
 *
 * Visibility-on-failure mirrors the rest of the Task API: callers who can't
 * see the task get 404, never 403, so item existence isn't leaked.
 */
class TaskAssigneeController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SerializerInterface $serializer,
    ) {
    }

    #[Route('/tasks/{id}/assignees', name: 'task_add_assignee', methods: ['POST'])]
    public function add(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $task = $this->findEditableTask($id, $user);
        if (null === $task) {
            return $this->json(['error' => 'Task not found.'], 404);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $userId = is_string($payload['userId'] ?? null) ? trim($payload['userId']) : '';
        if ('' === $userId || !Uuid::isValid($userId)) {
            return $this->json(['error' => 'A valid userId is required.'], 400);
        }

        $candidate = $this->em->getRepository(User::class)->find($userId);
        if (null === $candidate) {
            return $this->json(['error' => 'User not found.'], 404);
        }

        if (!$this->isAllowedAssignee($task, $candidate)) {
            return $this->json(
                ['error' => 'User must be the task owner or a member of its project.'],
                422,
            );
        }

        if ($task->getAssignees()->contains($candidate)) {
            return $this->json(['error' => 'User is already assigned to this task.'], 409);
        }

        $task->addAssignee($candidate);
        $this->em->flush();

        $json = $this->serializer->serialize($candidate, 'jsonld', ['groups' => ['user:read']]);
        return new JsonResponse($json, 200, [], true);
    }

    #[Route(
        '/tasks/{id}/assignees/{userId}',
        name: 'task_remove_assignee',
        methods: ['DELETE'],
    )]
    public function remove(string $id, string $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $task = $this->findEditableTask($id, $user);
        if (null === $task) {
            return $this->json(['error' => 'Task not found.'], 404);
        }

        if (!Uuid::isValid($userId)) {
            return $this->json(['error' => 'Invalid userId.'], 400);
        }

        $candidate = $this->em->getRepository(User::class)->find($userId);
        if (null === $candidate || !$task->getAssignees()->contains($candidate)) {
            return $this->json(['error' => 'Assignee not found on this task.'], 404);
        }

        $task->removeAssignee($candidate);
        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    /**
     * Returns the task only when the current user can edit it (admin, owner,
     * or project member). Returns null otherwise so callers can respond 404
     * uniformly without leaking task existence.
     */
    private function findEditableTask(string $id, User $user): ?Task
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $task = $this->em->getRepository(Task::class)->find($id);
        if (null === $task) {
            return null;
        }
        if ($this->isGranted('ROLE_ADMIN')) {
            return $task;
        }
        if ($task->getOwner()?->getId()?->equals($user->getId())) {
            return $task;
        }
        $project = $task->getProject();
        if ($project instanceof Project && $project->getMembers()->contains($user)) {
            return $task;
        }
        return null;
    }

    /**
     * Mirrors the ValidAssignees rule: a task's owner is always assignable;
     * for project tasks the project's members are too. Personal tasks (no
     * project) admit only the owner.
     */
    private function isAllowedAssignee(Task $task, User $candidate): bool
    {
        $owner = $task->getOwner();
        if (null !== $owner && $owner->getId()?->equals($candidate->getId())) {
            return true;
        }
        $project = $task->getProject();
        if ($project instanceof Project) {
            foreach ($project->getMembers() as $member) {
                if ($member->getId()?->equals($candidate->getId())) {
                    return true;
                }
            }
        }
        return false;
    }
}
