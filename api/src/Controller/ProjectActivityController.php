<?php

namespace App\Controller;

use App\Entity\CustomFieldDefinition;
use App\Entity\Project;
use App\Entity\Task;
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

        $projectId = (string) $project->getId();

        $taskIds = array_map(
            static fn (Task $t): string => (string) $t->getId(),
            $project->getTasks()->toArray(),
        );

        $cfdIds = array_map(
            static fn (CustomFieldDefinition $d): string => (string) $d->getId(),
            $this->em->getRepository(CustomFieldDefinition::class)
                ->findBy(['project' => $project]),
        );

        // Tasks and custom-field definitions deleted from this project keep their
        // audit rows (the log table has no FK back to them). Recover their object
        // ids from the versioned `project` recorded on each entry so the board's
        // history still shows a deleted item's lifecycle, ending in its remove
        // event. Gedmo serialises the versioned association as `{"id": "<uuid>"}`,
        // so reach into the nested id rather than comparing the whole object.
        $allTaskIds = array_values(array_unique(
            [...$taskIds, ...$this->deletedObjectIds(Task::class, $projectId)],
        ));
        $allCfdIds = array_values(array_unique(
            [...$cfdIds, ...$this->deletedObjectIds(CustomFieldDefinition::class, $projectId)],
        ));

        return new JsonResponse($this->activityFeed->forObjectGroups([
            Project::class => [$projectId],
            Task::class => $allTaskIds,
            CustomFieldDefinition::class => $allCfdIds,
        ], $request));
    }

    /**
     * Object ids of `$class` rows whose audit entries record this project via
     * the versioned `project` association — covers deleted items the entity no
     * longer references.
     *
     * @return list<string>
     */
    private function deletedObjectIds(string $class, string $projectId): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT object_id
            FROM ext_log_entries
            WHERE object_class = :class AND data->'project'->>'id' = :pid
            SQL;
        $rows = $this->em->getConnection()
            ->executeQuery($sql, ['class' => $class, 'pid' => $projectId])
            ->fetchFirstColumn();

        // object_id is a VARCHAR column, so values are strings; filter defensively.
        return array_values(array_filter(
            $rows,
            static fn (mixed $oid): bool => is_string($oid),
        ));
    }

    private function canRead(Project $project, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        return $project->isAccessibleBy($user);
    }
}
