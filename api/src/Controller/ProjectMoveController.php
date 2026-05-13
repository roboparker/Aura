<?php

namespace App\Controller;

use App\Entity\CustomFieldDefinition;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Moves a project — and its denormalised child rows (discussions,
 * custom field definitions) — into a different space (#182).
 *
 * Auth bar: caller must be a member of the project's CURRENT space
 * (so they can already write to it) AND of the TARGET space (so
 * they're authorised to drop content there). Same shape as the
 * project edit/delete predicates plus a target-membership check.
 *
 * Tasks don't carry a denormalised `space` FK — their access is
 * derived through `task.project.space`, so updating the project is
 * enough. Discussions and CustomFieldDefinitions cache the space on
 * insert via PrePersist, so we re-stamp them explicitly here. Page
 * is space-owned (not project-owned) and isn't touched by this move.
 *
 * Audit history is preserved automatically: Project is `Loggable`,
 * so the `space` change lands as a regular update entry.
 */
class ProjectMoveController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/projects/{id}/move', name: 'project_move', methods: ['POST'])]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return $this->json(['error' => 'Project not found.'], 404);
        }

        $project = $this->em->getRepository(Project::class)->find($id);
        if (null === $project) {
            return $this->json(['error' => 'Project not found.'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$project->isAccessibleBy($user)) {
            // Hide source-membership failures behind a 404 to match the
            // access-extension's existence-hiding shape.
            return $this->json(['error' => 'Project not found.'], 404);
        }

        $payload = json_decode($request->getContent(), true) ?? [];
        $rawSpace = $payload['space'] ?? null;
        if (!is_string($rawSpace) || '' === trim($rawSpace)) {
            return $this->json(['error' => 'Target `space` IRI is required.'], 400);
        }
        $spaceId = $this->extractIdFromIri($rawSpace);
        if (null === $spaceId) {
            return $this->json(['error' => 'Invalid space IRI.'], 400);
        }

        $target = $this->em->getRepository(Space::class)->find($spaceId);
        if (null === $target) {
            return $this->json(['error' => 'Target space not found.'], 404);
        }
        if (!$this->isGranted('ROLE_ADMIN') && !$target->hasMember($user)) {
            // Hide target existence to mirror SpaceAccessExtension.
            return $this->json(['error' => 'Target space not found.'], 404);
        }

        $current = $project->getSpace();
        if (null !== $current && $current->getId()?->equals($target->getId())) {
            // No-op move — return the project unchanged with 200 rather
            // than a 304 so the PWA's "Move to" UX gets a consistent
            // response shape even if the user picks the current space.
            return $this->json([
                '@id' => '/projects/' . $project->getId(),
                'id' => (string) $project->getId(),
                'space' => '/spaces/' . $target->getId(),
                'moved' => false,
            ], 200);
        }

        $project->setSpace($target);

        // Re-stamp child entities that cache the parent space on insert
        // (`CustomFieldDefinition::syncSpaceFromProject`). Without this
        // their cached space drifts out of sync with the project's,
        // breaking access extensions and search filters. Discussions
        // live on the space directly (no project link) and stay in
        // their source space — they aren't carried by the move.
        foreach (
            $this->em->getRepository(CustomFieldDefinition::class)
            ->findBy(['project' => $project]) as $definition
        ) {
            $definition->setSpace($target);
        }

        $this->em->flush();

        return $this->json([
            '@id' => '/projects/' . $project->getId(),
            'id' => (string) $project->getId(),
            'space' => '/spaces/' . $target->getId(),
            'moved' => true,
        ], 200);
    }

    /**
     * Accepts either an IRI (`/spaces/{uuid}`) or a bare UUID — the
     * PWA serialises spaces as IRIs, but tests and curl-by-hand often
     * pass the raw id, so be tolerant.
     */
    private function extractIdFromIri(string $iri): ?string
    {
        $trimmed = trim($iri);
        if (Uuid::isValid($trimmed)) {
            return $trimmed;
        }
        if (1 === preg_match('#^/spaces/([0-9a-f-]+)$#i', $trimmed, $m) && Uuid::isValid($m[1])) {
            return $m[1];
        }
        return null;
    }
}
