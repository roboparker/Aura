<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\CustomFieldDefinition;
use App\Entity\Project;
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
 * Project-level change log for the custom-fields schema:
 * `GET /projects/{id}/custom_field_definitions/activity`. Merges the
 * Gedmo audit history of every custom field definition currently in the
 * project, newest-first, reusing {@see ActivityFeedQuery} so the shape
 * matches the task/project feeds (rows + actor map).
 *
 * History for fields that have since been deleted falls out of scope —
 * we can only key on the project's current definition ids. Access mirrors
 * the project surface: any space member; 404 otherwise.
 */
class CustomFieldDefinitionActivityController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ActivityFeedQuery $activityFeed,
    ) {
    }

    #[Route(
        '/projects/{id}/custom_field_definitions/activity',
        name: 'project_custom_field_definitions_activity',
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

        $project = $this->em->getRepository(Project::class)->find($id);
        if (null === $project || !$this->canRead($project, $user)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $definitions = $this->em->getRepository(CustomFieldDefinition::class)
            ->findBy(['project' => $project]);
        $objectIds = array_values(
            array_map(static fn (CustomFieldDefinition $d): string => (string) $d->getId(), $definitions),
        );

        return new JsonResponse(
            $this->activityFeed->forClass(CustomFieldDefinition::class, $objectIds, $request),
        );
    }

    private function canRead(Project $project, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        return $project->isAccessibleBy($user);
    }
}
