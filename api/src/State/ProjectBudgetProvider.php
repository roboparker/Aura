<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Project;
use App\Service\ProjectBudgetCalculator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Enriches project reads with budget consumption (#651) — the transient
 * `budgetSpent` field the PWA's progress bars render — in one grouped query
 * per page, mirroring {@see DiscussionAggregateProvider}. Delegates to the
 * stock Doctrine providers so access scoping / pagination / 404s behave
 * exactly as before.
 *
 * @implements ProviderInterface<Project>
 */
final class ProjectBudgetProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<Project> $collectionProvider
     * @param ProviderInterface<Project> $itemProvider
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private ProviderInterface $collectionProvider,
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private ProviderInterface $itemProvider,
        private ProjectBudgetCalculator $calculator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            $data = $this->collectionProvider->provide($operation, $uriVariables, $context);
            if (is_iterable($data)) {
                $projects = [];
                foreach ($data as $project) {
                    $projects[] = $project;
                }
                $this->enrich($projects);
            }

            return $data;
        }

        $item = $this->itemProvider->provide($operation, $uriVariables, $context);
        if ($item instanceof Project) {
            $this->enrich([$item]);
        }

        return $item;
    }

    /**
     * @param list<Project> $projects
     */
    private function enrich(array $projects): void
    {
        $budgeted = array_values(array_filter(
            $projects,
            static fn (Project $e): bool => null !== $e->getBudgetType(),
        ));
        if ([] === $budgeted) {
            return;
        }

        $spent = $this->calculator->spentByProject($budgeted);
        foreach ($budgeted as $project) {
            $row = $spent[(string) $project->getId()] ?? ['seconds' => 0, 'fees' => 0];
            $project->setBudgetSpent(
                Project::BUDGET_HOURS === $project->getBudgetType()
                    ? intdiv($row['seconds'], 60)
                    : $row['fees'],
            );
        }
    }
}
