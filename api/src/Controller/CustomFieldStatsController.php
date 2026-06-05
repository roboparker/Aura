<?php

declare(strict_types=1);

namespace App\Controller;

use App\CustomField\CustomFieldKind;
use App\Entity\CustomFieldDefinition;
use App\Entity\CustomFieldValue;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Read-only usage stats for the custom-fields schema editor:
 *
 *   - `GET /projects/{id}/custom_field_stats` — per-definition "filled"
 *     counts (how many of the project's tasks carry a non-null value)
 *     plus the project's total task count, so the editor can show "6/8".
 *   - `GET /custom_field_definitions/{id}/option_stats` — per-option
 *     usage counts for a select field (the "Web · 168" numbers).
 *
 * Access mirrors the rest of the project surface: caller must be a member
 * of the field's space; non-members get 404 to keep the existence-hiding
 * shape consistent.
 */
class CustomFieldStatsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route(
        '/projects/{id}/custom_field_stats',
        name: 'project_custom_field_stats',
        methods: ['GET'],
    )]
    public function projectStats(string $id, #[CurrentUser] ?User $user): Response
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

        $total = (int) $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Task::class, 't')
            ->where('t.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();

        // At most one value row per (task, definition) thanks to the unique
        // constraint, so a plain COUNT over non-null values is the number
        // of tasks that have filled the field.
        /** @var list<array{did: Uuid|string, filled: int|string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(cfv.definition) AS did', 'COUNT(cfv.id) AS filled')
            ->from(CustomFieldValue::class, 'cfv')
            ->join('cfv.definition', 'd')
            ->where('d.project = :project')
            ->andWhere('cfv.value IS NOT NULL')
            ->setParameter('project', $project)
            ->groupBy('cfv.definition')
            ->getQuery()
            ->getArrayResult();

        $filledByDef = [];
        foreach ($rows as $row) {
            $did = $row['did'];
            $key = $did instanceof Uuid ? $did->toRfc4122() : $did;
            $filledByDef[$key] = (int) $row['filled'];
        }

        $definitions = $this->em->getRepository(CustomFieldDefinition::class)
            ->findBy(['project' => $project], ['position' => 'ASC']);

        $stats = array_map(function (CustomFieldDefinition $def) use ($filledByDef): array {
            $key = (string) $def->getId();
            return [
                'definition' => '/custom_field_definitions/' . $def->getId(),
                'filled' => $filledByDef[$key] ?? 0,
            ];
        }, $definitions);

        return new JsonResponse(['total' => $total, 'stats' => $stats]);
    }

    #[Route(
        '/custom_field_definitions/{id}/option_stats',
        name: 'custom_field_definition_option_stats',
        methods: ['GET'],
    )]
    public function optionStats(string $id, #[CurrentUser] ?User $user): Response
    {
        if (null === $user) {
            return new JsonResponse(['error' => 'Not authenticated.'], 401);
        }
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        $definition = $this->em->getRepository(CustomFieldDefinition::class)->find($id);
        $project = $definition?->getProject();
        if (null === $definition || null === $project || !$this->canRead($project, $user)) {
            return new JsonResponse(['error' => 'Not found.'], 404);
        }

        if (CustomFieldKind::SELECT->value !== $definition->getKind()) {
            return new JsonResponse(['options' => []]);
        }

        $counts = [];
        /** @var list<CustomFieldValue> $values */
        $values = $this->em->getRepository(CustomFieldValue::class)
            ->findBy(['definition' => $definition]);
        foreach ($values as $cfv) {
            foreach ($this->keysOf($cfv->getValue()) as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $config = $definition->getConfig();
        $options = (isset($config['options']) && is_array($config['options'])) ? $config['options'] : [];

        $result = [];
        foreach ($options as $option) {
            if (!is_array($option) || !isset($option['key']) || !is_string($option['key'])) {
                continue;
            }
            $key = $option['key'];
            $label = (isset($option['label']) && is_string($option['label'])) ? $option['label'] : $key;
            $result[] = ['key' => $key, 'label' => $label, 'count' => $counts[$key] ?? 0];
        }

        return new JsonResponse(['options' => $result]);
    }

    /**
     * Normalise a stored select value to the option keys it references.
     * Single-select stores a bare string; multi-select stores a list of
     * strings.
     *
     * @return list<string>
     */
    private function keysOf(mixed $value): array
    {
        if (is_string($value) && '' !== $value) {
            return [$value];
        }
        if (is_array($value)) {
            return array_values(array_filter(
                $value,
                static fn (mixed $v): bool => is_string($v) && '' !== $v,
            ));
        }
        return [];
    }

    private function canRead(Project $project, User $user): bool
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return true;
        }
        return $project->isAccessibleBy($user);
    }
}
