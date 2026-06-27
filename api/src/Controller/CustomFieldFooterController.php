<?php

declare(strict_types=1);

namespace App\Controller;

use App\CustomField\Footer\FooterAggregator;
use App\Doctrine\SpaceMembershipDql;
use App\Entity\CustomFieldDefinition;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /projects/{id}/custom_field_footers` — returns the aggregated
 * footer values for every CFD in the project that declared a
 * `footer.kind`. Each result row contains the definition IRI, the
 * aggregation kind, the optional client-supplied label, and the
 * computed value (shape per kind — number for numeric, ISO date
 * string for date, `{amount, currency}` for money).
 *
 * Query params honoured (mirroring the Task collection filter chain):
 *
 *   - `search`  — full-text search on Task title/description.
 *   - `status`  — `open` / `completed`.
 *   - `overdue` — `true` shortlists tasks with a past dueDate that
 *                 aren't yet completed.
 *   - `dueDate[before|after|strictly_before|strictly_after]`.
 *
 * Anything else is ignored. The PWA only sends what it filters in the
 * task view, and overzealous param forwarding would let a client
 * pivot the footer endpoint into a leak of unrelated state.
 *
 * Access: caller must be a member of the project's space (matches
 * the `Project` Get security expression); non-members get 404 so the
 * endpoint mirrors the existence-hiding shape of the rest of the
 * project surface.
 */
class CustomFieldFooterController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FooterAggregator $aggregator,
    ) {
    }

    #[Route(
        '/projects/{id}/custom_field_footers',
        name: 'project_custom_field_footers',
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

        // The fields this project has opted into, in space order.
        $definitions = $project->getCustomFieldDefinitions()->toArray();
        usort(
            $definitions,
            static fn (CustomFieldDefinition $a, CustomFieldDefinition $b): int
                => $a->getPosition() <=> $b->getPosition(),
        );
        $haveFooters = array_filter(
            $definitions,
            static fn (CustomFieldDefinition $d) => null !== $d->getFooter(),
        );

        // Cheap exit when no field has a footer configured — saves the
        // task-id materialisation entirely.
        if ([] === $haveFooters) {
            return new JsonResponse(['footers' => []]);
        }

        $taskIds = $this->scopedTaskIds($project, $user, $request);
        $rows = $this->aggregator->aggregate($haveFooters, $taskIds);

        return new JsonResponse([
            'footers' => array_map(static function (array $row): array {
                /** @var CustomFieldDefinition $def */
                $def = $row['definition'];
                return [
                    'definition' => '/custom_field_definitions/' . $def->getId(),
                    'name' => $def->getName(),
                    'kind' => $row['kind'],
                    'label' => $row['label'],
                    'value' => $row['value'],
                ];
            }, $rows),
        ]);
    }

    /**
     * @return list<string> UUIDs (rfc4122 strings) of the in-scope task set.
     */
    private function scopedTaskIds(Project $project, User $user, Request $request): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('t.id')
            ->from(Task::class, 't')
            ->join('t.project', 'p')
            ->where('p = :project')
            ->andWhere(SpaceMembershipDql::userBelongsToProjectSpace('p'))
            ->setParameter('project', $project)
            ->setParameter('user', $user);

        $status = $request->query->get('status');
        if (\in_array($status, ['open', 'completed'], true)) {
            if ('completed' === $status) {
                $qb->andWhere('t.completedOn IS NOT NULL');
            } else {
                $qb->andWhere('t.completedOn IS NULL');
            }
        }

        if ('true' === strtolower($request->query->get('overdue', ''))) {
            $qb->andWhere('t.completedOn IS NULL')
                ->andWhere('t.dueDate IS NOT NULL')
                ->andWhere('t.dueDate < :now')
                ->setParameter('now', new \DateTimeImmutable());
        }

        $dueDate = $request->query->all()['dueDate'] ?? null;
        if (is_array($dueDate)) {
            /** @var array<string, mixed> $dueDate */
            $this->applyDueDateBounds($qb, $dueDate);
        }

        $search = $request->query->get('search');
        if (is_string($search) && '' !== trim($search)) {
            // FTS via the SEARCH_VECTOR_MATCH DQL function (declared in
            // doctrine.yaml). websearch_to_tsquery is forgiving — quoted
            // phrases, `-exclusions`, naked words all work without
            // pre-parsing on our side.
            $qb->andWhere("SEARCH_VECTOR_MATCH(t.searchVector, :ftsQuery) = TRUE")
                ->setParameter('ftsQuery', trim($search));
        }

        // Scope to a board section so the list view can footer each section
        // independently. `none` = the default (unsectioned) group; an IRI or
        // bare UUID = that section; omitting it aggregates the whole board.
        $section = $request->query->get('section');
        if ('none' === $section) {
            $qb->andWhere('t.section IS NULL');
        } elseif (is_string($section) && '' !== $section) {
            $sectionId = str_contains($section, '/')
                ? substr((string) strrchr($section, '/'), 1)
                : $section;
            if (Uuid::isValid($sectionId)) {
                $qb->andWhere('t.section = :section')
                    ->setParameter('section', $sectionId);
            }
        }

        /** @var list<array{id: Uuid|string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $ids = [];
        foreach ($rows as $row) {
            $idValue = $row['id'];
            if ($idValue instanceof Uuid) {
                $ids[] = $idValue->toRfc4122();
            } elseif (is_string($idValue)) {
                $ids[] = $idValue;
            }
        }
        return $ids;
    }

    /**
     * @param array<string, mixed> $bounds
     */
    private function applyDueDateBounds(\Doctrine\ORM\QueryBuilder $qb, array $bounds): void
    {
        $map = [
            'before' => ['t.dueDate <= :dueBefore', 'dueBefore'],
            'strictly_before' => ['t.dueDate < :dueStrictlyBefore', 'dueStrictlyBefore'],
            'after' => ['t.dueDate >= :dueAfter', 'dueAfter'],
            'strictly_after' => ['t.dueDate > :dueStrictlyAfter', 'dueStrictlyAfter'],
        ];
        foreach ($map as $key => [$predicate, $param]) {
            $value = $bounds[$key] ?? null;
            if (!is_string($value) || '' === trim($value)) {
                continue;
            }
            try {
                $date = new \DateTimeImmutable($value);
            } catch (\Exception) {
                continue;
            }
            $qb->andWhere($predicate)->setParameter($param, $date);
        }
    }

    private function canRead(Project $project, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        return $project->isAccessibleBy($user);
    }
}
