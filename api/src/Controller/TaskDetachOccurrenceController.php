<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /tasks/{id}/detach-occurrence` — split a single occurrence out of a
 * recurring series (the "this occurrence only" branch of a calendar drag).
 *
 * Body: `{ "date": "Y-m-d", "dueDate": "Y-m-d" }` — `date` is the occurrence
 * being detached (added to the series' EXDATE set so it stops projecting), and
 * `dueDate` is where the detached copy lands. The copy is a standalone,
 * non-recurring task cloning title/description/project/tags/assignees/reminders
 * from the series (mirroring the on-completion spawn), owned by the series
 * owner, inserted at the top of that owner's list.
 *
 * Editing the "all occurrences" case is a plain `PATCH /tasks/{id}` of the
 * anchor `dueDate`, so it needs no endpoint here.
 */
final class TaskDetachOccurrenceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TaskRepository $tasks,
    ) {
    }

    #[Route('/tasks/{id}/detach-occurrence', name: 'task_detach_occurrence', methods: ['POST'])]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Task not found.'], 404);
        }

        $task = $this->tasks->find($id);
        if (null === $task) {
            return $this->json(['error' => 'Task not found.'], 404);
        }
        // Not readable at all → hide behind a 404, matching the access shape.
        if (
            !$this->isGranted('ROLE_ADMIN')
            && $task->getOwner() !== $user
            && !$task->isAccessibleBy($user)
        ) {
            return $this->json(['error' => 'Task not found.'], 404);
        }
        // Readable but not editable → 403.
        $canEdit = $this->isGranted('ROLE_ADMIN')
            || $task->getOwner() === $user
            || $this->isGranted('space.tasks.update', $task);
        if (!$canEdit) {
            return $this->json(['error' => 'You cannot edit this task.'], 403);
        }

        $anchor = $task->getDueDate();
        if (null === $task->getRecurrenceRule() || null === $anchor || null !== $task->getCompletedOn()) {
            return $this->json(['error' => 'Task is not an active recurring series.'], 400);
        }

        $payload = $request->toArray();
        $occurrenceDay = $this->parseDay(is_string($payload['date'] ?? null) ? $payload['date'] : '');
        $targetDay = $this->parseDay(is_string($payload['dueDate'] ?? null) ? $payload['dueDate'] : '');
        if (null === $occurrenceDay || null === $targetDay) {
            return $this->json(['error' => '`date` and `dueDate` (Y-m-d) are required.'], 400);
        }

        // Land the detached copy at the target day, keeping the series' time.
        $newDue = $targetDay->setTime(
            (int) $anchor->format('H'),
            (int) $anchor->format('i'),
            (int) $anchor->format('s'),
        );

        $detached = new Task();
        $detached->setOwner($task->getOwner());
        $detached->setProject($task->getProject());
        $detached->setTitle($task->getTitle());
        $detached->setDescription($task->getDescription());
        $detached->setDueDate($newDue);
        $detached->setReminders($task->getReminders());
        foreach ($task->getTags() as $tag) {
            $detached->addTag($tag);
        }
        foreach ($task->getAssignees() as $assignee) {
            $detached->addAssignee($assignee);
        }
        $owner = $task->getOwner();
        if (null !== $owner) {
            $min = $this->tasks->findMinPositionForOwner($owner);
            $detached->setPosition(null === $min ? 0 : $min - 1);
        }

        // Skip the original slot so the series stops projecting that day.
        $task->addRecurrenceException($occurrenceDay->format('Y-m-d'));

        $this->em->persist($detached);
        $this->em->flush();

        return $this->json([
            'id' => (string) $detached->getId(),
            '@id' => '/tasks/' . $detached->getId(),
            'dueDate' => $newDue->format(\DateTimeInterface::ATOM),
        ], 201);
    }

    private function parseDay(string $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }
}
