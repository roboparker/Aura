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
 * Bulk reorder the authenticated user's tasks. Accepts an ordered list of
 * Task IRIs and renumbers their `position` to match (0, 1, 2, ...).
 *
 * This is an all-or-nothing operation — it rejects the whole payload if any
 * IRI is malformed, unknown, not owned by the current user, or if the list
 * does not cover exactly the user's current task set. Covering the full set
 * prevents partial writes from interleaving awkwardly with concurrent
 * creates/deletes, and keeps positions contiguous and easy to reason about.
 *
 * Admins reorder their own tasks only; cross-user reordering is intentionally
 * not exposed via this endpoint.
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
        $owned = $this->tasks->findBy(['owner' => $user]);
        $ownedById = [];
        foreach ($owned as $task) {
            $ownedById[(string) $task->getId()] = $task;
        }

        // Ownership check runs first so cross-user or non-existent IRIs return
        // 404 rather than leaking existence via a 400 count-mismatch response.
        // This mirrors TaskOwnerExtension's item-lookup behavior.
        foreach ($requestedIds as $id) {
            if (!isset($ownedById[$id])) {
                return $this->json(['error' => sprintf('Task %s not found.', $id)], 404);
            }
        }

        if (count($requestedIds) !== count($ownedById)) {
            return $this->json(['error' => 'Reorder payload must include every one of your tasks exactly once.'], 400);
        }

        foreach ($requestedIds as $index => $id) {
            /** @var Task $task */
            $task = $ownedById[$id];
            $task->setPosition($index);
        }

        $this->em->flush();

        return new JsonResponse(null, 204);
    }
}
