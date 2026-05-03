<?php

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
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

/**
 * Bulk reorder the tasks the user can act on. Accepts an ordered list of
 * Task IRIs and renumbers `position` (0, 1, 2, ...) on each reorderable
 * task in the input.
 *
 * "Reorderable" matches the project-member edit rule on Task: the user
 * can reorder tasks they own plus any task in a project they belong to
 * (anyone who can add a task to the project can also reorder its tasks).
 * Tasks outside that set — e.g. another user's personal tasks an admin's
 * unfiltered view turns up — are silently skipped, so the frontend can
 * send the full visible list without classifying rows up-front.
 *
 * Still rejects the whole payload when:
 *   - any IRI is malformed,
 *   - the input contains a duplicate, or
 *   - the input doesn't include every one of the reorderable tasks.
 * The "every one" requirement keeps position numbers contiguous.
 */
final class TaskReorderController extends AbstractController
{
    public function __construct(
        private TaskRepository $tasks,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/tasks/reorder', name: 'task_reorder', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !isset($payload['order']) || !is_array($payload['order'])) {
            return $this->json(['error' => 'Expected body: {"order": ["/tasks/{uuid}", ...]}.'], 400);
        }

        $ids = [];
        foreach ($payload['order'] as $iri) {
            if (!is_string($iri) || !preg_match('#^/tasks/([0-9a-fA-F-]{36})$#', $iri, $match) || !Uuid::isValid($match[1])) {
                return $this->json(['error' => sprintf('Invalid Task IRI: %s', is_string($iri) ? $iri : gettype($iri))], 400);
            }
            $id = Uuid::fromString($match[1])->toRfc4122();
            if (isset($ids[$id])) {
                return $this->json(['error' => sprintf('Duplicate Task IRI: %s', $iri)], 400);
            }
            $ids[$id] = true;
        }

        $requestedIds = array_keys($ids);
        $reorderable = $this->tasks->findReorderableForUser($user);
        $reorderableById = [];
        foreach ($reorderable as $task) {
            $reorderableById[(string) $task->getId()] = $task;
        }

        // Walk the input in order, renumbering each reorderable task as we
        // hit it. IRIs that aren't reorderable (other users' personal tasks
        // an admin's unfiltered view turns up, etc.) are skipped so the
        // frontend can send the full visible list without classifying rows.
        $renumbered = [];
        $position = 0;
        foreach ($requestedIds as $id) {
            if (!isset($reorderableById[$id])) {
                continue;
            }
            $reorderableById[$id]->setPosition($position++);
            $renumbered[$id] = true;
        }

        // Every reorderable task must have appeared somewhere in the input —
        // otherwise we'd leave gaps or duplicate `position` values.
        if (count($renumbered) !== count($reorderableById)) {
            return $this->json([
                'error' => 'Reorder payload must include every one of your tasks exactly once.',
            ], 400);
        }

        $this->em->flush();

        return new JsonResponse(null, 204);
    }
}
