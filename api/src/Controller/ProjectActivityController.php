<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\User;
use App\Service\ActivityFeedQuery;
use App\Service\ProjectActivityScope;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Reads the audit history for a project AND every task and custom-field
 * definition that belongs to it. Access mirrors the Project GET security
 * expression — any project member can read; non-members get a 404.
 *
 * The history feed combines `Project` rows with `Task` and
 * `CustomFieldDefinition` rows (filtered by the project's ids, including
 * deleted ones) and orders them all by `loggedAt` so the PWA sees a single
 * chronological stream.
 */
class ProjectActivityController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ActivityFeedQuery $activityFeed,
        private ProjectActivityScope $scope,
    ) {
    }

    #[Route(
        '/projects/{id}/activity',
        name: 'project_activity',
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

        // The project's audit history rolls up its tasks and custom-field
        // definitions — including deleted children, recovered via the versioned
        // `project` on their log rows. The same grouping feeds the space-level
        // feed one level up, so the hierarchy stays consistent.
        return new JsonResponse(
            $this->activityFeed->forObjectGroups($this->scope->groupsForProject($project), $request),
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
