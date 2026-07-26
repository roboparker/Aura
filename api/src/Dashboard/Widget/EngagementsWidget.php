<?php

declare(strict_types=1);

namespace App\Dashboard\Widget;

use App\Dashboard\WidgetDefinitionInterface;
use App\Entity\Project;
use App\Entity\Space;
use App\Entity\User;
use App\Security\Permission\SpacePermission;
use App\Service\ProjectBudgetCalculator;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Active engagements with their budget consumption — the `/projects` table,
 * condensed to what's worth glancing at from a dashboard.
 *
 * Budget spend comes from the same {@see ProjectBudgetCalculator} the
 * `/projects` page uses, so the two can't disagree about how much of a budget
 * is gone. Duplicating that arithmetic here is exactly the kind of drift that
 * makes two screens quietly contradict each other.
 *
 * Rides `invoices`: budgets and rates are commercial terms, not something every
 * member of a space should see by default.
 */
final class EngagementsWidget implements WidgetDefinitionInterface
{
    private const MAX_ROWS = 20;

    public function __construct(
        private EntityManagerInterface $em,
        private ProjectBudgetCalculator $budgets,
    ) {
    }

    public function type(): string
    {
        return 'engagements.table';
    }

    public function name(): string
    {
        return 'Engagements';
    }

    public function description(): string
    {
        return 'Active client engagements and how much of each budget is used.';
    }

    public function group(): string
    {
        return 'Billing';
    }

    public function permissionCategory(array $config = []): string
    {
        return SpacePermission::INVOICES;
    }

    public function defaultSize(): array
    {
        return ['w' => 2, 'h' => 2];
    }

    public function configSchema(): array
    {
        return [
            [
                'key' => 'onlyOverBudget',
                'type' => 'boolean',
                'label' => 'Only show engagements over budget',
            ],
            [
                'key' => 'limit',
                'type' => 'number',
                'label' => 'Rows',
                'min' => 1,
                'max' => self::MAX_ROWS,
            ],
        ];
    }

    public function validateConfig(array $config): ?string
    {
        $limit = $config['limit'] ?? 5;
        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_ROWS) {
            return sprintf('Rows must be a number between 1 and %d.', self::MAX_ROWS);
        }

        if (array_key_exists('onlyOverBudget', $config) && !is_bool($config['onlyOverBudget'])) {
            return 'Only show engagements over budget must be true or false.';
        }

        return null;
    }

    public function data(Space $space, array $config, User $user): array
    {
        $limit = is_int($config['limit'] ?? null) ? max(1, min(self::MAX_ROWS, $config['limit'])) : 5;
        $onlyOverBudget = true === ($config['onlyOverBudget'] ?? false);

        /** @var list<Project> $projects */
        $projects = $this->em->getRepository(Project::class)
            ->createQueryBuilder('p')
            ->where('p.space = :space')
            ->andWhere('p.archived = false')
            ->setParameter('space', $space)
            ->orderBy('p.name', 'ASC')
            // Over-budget filtering needs the computed spend, so it can't be a
            // WHERE clause; fetch a wider slice and trim after.
            ->setMaxResults($onlyOverBudget ? self::MAX_ROWS * 2 : $limit)
            ->getQuery()
            ->getResult();

        if ([] === $projects) {
            return ['rows' => []];
        }

        $budgeted = array_values(array_filter(
            $projects,
            static fn (Project $p): bool => null !== $p->getBudgetType(),
        ));
        $spent = [] === $budgeted ? [] : $this->budgets->spentByProject($budgeted);

        $rows = [];
        foreach ($projects as $project) {
            $type = $project->getBudgetType();
            $amount = $project->getBudgetAmount();

            $used = null;
            if (null !== $type) {
                $row = $spent[(string) $project->getId()] ?? ['seconds' => 0, 'fees' => 0];
                $used = Project::BUDGET_HOURS === $type ? intdiv($row['seconds'], 60) : $row['fees'];
            }

            $pct = (null !== $amount && $amount > 0 && null !== $used)
                ? (int) round(($used / $amount) * 100)
                : null;

            if ($onlyOverBudget && (null === $pct || $pct < 100)) {
                continue;
            }

            $rows[] = [
                'id' => (string) $project->getId(),
                'name' => $project->getName(),
                'client' => $project->getClient()?->getName(),
                'currency' => $project->getCurrency(),
                'budgetType' => $type,
                'budgetAmount' => $amount,
                'budgetSpent' => $used,
                'percent' => $pct,
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return ['rows' => $rows];
    }
}
