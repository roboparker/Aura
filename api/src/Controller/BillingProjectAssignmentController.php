<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BillingProject;
use App\Entity\Project;
use App\Entity\User;
use App\Security\Permission\SpacePermission;
use App\Security\Permission\SpacePermissionResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Assign task-management {@see Project}s to a {@see BillingProject} from the
 * billing project's page. `PUT /billing_projects/{id}/projects` replaces the
 * whole assigned set (body `{ projects: [iri|uuid, ...] }`): projects in the
 * list are pointed at this billing project, and any previously-assigned project
 * not in the list is unassigned. Space-admin / invoices-update gated; every
 * project must live in the same space.
 */
class BillingProjectAssignmentController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private SpacePermissionResolver $permissions,
    ) {
    }

    #[Route('/billing_projects/{id}/projects', name: 'billing_project_assign', methods: ['PUT'])]
    public function __invoke(string $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (null === $user) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        $billingProject = $this->em->getRepository(BillingProject::class)->find($id);
        $space = $billingProject?->getSpace();
        // Existence-hiding: unknown project / non-members get 404.
        if (null === $billingProject || null === $space || !($this->isGranted('ROLE_ADMIN') || $space->hasMember($user))) {
            return $this->json(['error' => 'Billing project not found.'], 404);
        }
        if (
            !$this->isGranted('ROLE_ADMIN')
            && !$space->isAdmin($user)
            && !$this->permissions->canByExplicitGrant($user, $space, SpacePermission::INVOICES, SpacePermission::UPDATE)
        ) {
            return $this->json(['error' => 'You cannot manage this billing project.'], 403);
        }

        $payload = $request->toArray();
        $raw = $payload['projects'] ?? [];
        if (!is_array($raw)) {
            return $this->json(['error' => 'projects must be an array of project IRIs.'], 422);
        }

        $wanted = [];
        foreach ($raw as $entry) {
            if (!is_string($entry)) {
                return $this->json(['error' => 'Each project must be an IRI string.'], 422);
            }
            $uuid = Uuid::isValid($entry) ? $entry : substr($entry, (int) strrpos($entry, '/') + 1);
            if (!Uuid::isValid($uuid)) {
                return $this->json(['error' => sprintf('Invalid project IRI: %s', $entry)], 422);
            }
            $project = $this->em->getRepository(Project::class)->find($uuid);
            if (null === $project || true !== $project->getSpace()?->getId()?->equals($space->getId())) {
                return $this->json(['error' => 'Project not found in this space.'], 404);
            }
            $wanted[(string) $project->getId()] = $project;
        }

        // Unassign projects currently on this billing project but no longer wanted.
        foreach ($billingProject->getAssignedProjects() as $current) {
            if (!isset($wanted[(string) $current->getId()])) {
                $current->setBillingProject(null);
            }
        }
        // Assign the wanted set (idempotent; steals a project from another billing project).
        foreach ($wanted as $project) {
            $project->setBillingProject($billingProject);
        }

        $this->em->flush();

        return $this->json([
            'ok' => true,
            'projects' => array_map(static fn (Project $p): string => (string) $p->getId(), array_values($wanted)),
        ]);
    }
}
