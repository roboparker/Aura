<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CustomFieldDefinition;
use App\Entity\Project;
use App\Entity\ProjectFieldVisibility;
use App\Entity\User;
use App\Repository\ProjectFieldVisibilityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * `PUT /projects/{id}/custom_field_definitions/{defId}/visibility` — set where a
 * field is shown WITHIN this project (#custom-fields-project): the task list,
 * the Kanban board, or both. Body: `{"visibility": "list"|"board"|"both"}`.
 *
 * Upserts a {@see ProjectFieldVisibility} row for the (project, definition)
 * pair; the definition's own column stays the cross-project default. The field
 * must already be attached to the project.
 *
 * Access mirrors the reorder/define endpoints: any space member of the project
 * (admins always). Non-members — and unknown ids — get 404 to match the
 * existence-hiding shape of the rest of the project surface.
 */
final class CustomFieldVisibilityController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectFieldVisibilityRepository $overrides,
    ) {
    }

    #[Route(
        '/projects/{id}/custom_field_definitions/{defId}/visibility',
        name: 'project_custom_field_definition_visibility',
        methods: ['PUT'],
    )]
    public function __invoke(
        string $id,
        string $defId,
        Request $request,
        #[CurrentUser] ?User $user,
    ): Response {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id) || !Uuid::isValid($defId)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $project = $this->em->getRepository(Project::class)->find($id);
        if (null === $project || !$this->canManage($project, $user)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $definition = $this->em->getRepository(CustomFieldDefinition::class)->find($defId);
        if (null === $definition || !$project->getCustomFieldDefinitions()->contains($definition)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $payload = $request->toArray();
        $raw = $payload['visibility'] ?? null;
        // Accept a comma-joined SET of surfaces (list/board/calendar) or the
        // legacy single value; normalise to a deduped, ordered surface string.
        $surfaces = is_string($raw) ? CustomFieldDefinition::visibilitySurfaces($raw) : [];
        if ([] === $surfaces) {
            return new JsonResponse(
                ['error' => 'visibility must list one or more of: ' . implode(', ', CustomFieldDefinition::SURFACES) . '.'],
                400,
            );
        }
        $visibility = implode(',', array_values(array_unique($surfaces)));

        $row = $this->overrides->findOneFor($project, $definition);
        if (null === $row) {
            $row = (new ProjectFieldVisibility())
                ->setProject($project)
                ->setDefinition($definition);
            $this->em->persist($row);
        }
        $row->setVisibility($visibility);
        $this->em->flush();

        return new JsonResponse(['visibility' => $visibility], 200);
    }

    private function canManage(Project $project, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }

        // Fields are managed by any space member (#custom-fields-space).
        return $project->isAccessibleBy($user);
    }
}
