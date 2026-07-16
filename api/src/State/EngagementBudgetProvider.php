<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Engagement;
use App\Service\EngagementBudgetCalculator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Enriches engagement reads with budget consumption (#651) — the transient
 * `budgetSpent` field the PWA's progress bars render — in one grouped query
 * per page, mirroring {@see DiscussionAggregateProvider}. Delegates to the
 * stock Doctrine providers so access scoping / pagination / 404s behave
 * exactly as before.
 *
 * @implements ProviderInterface<Engagement>
 */
final class EngagementBudgetProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<Engagement> $collectionProvider
     * @param ProviderInterface<Engagement> $itemProvider
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private ProviderInterface $collectionProvider,
        #[Autowire(service: 'api_platform.doctrine.orm.state.item_provider')]
        private ProviderInterface $itemProvider,
        private EngagementBudgetCalculator $calculator,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            $data = $this->collectionProvider->provide($operation, $uriVariables, $context);
            if (is_iterable($data)) {
                $engagements = [];
                foreach ($data as $engagement) {
                    $engagements[] = $engagement;
                }
                $this->enrich($engagements);
            }

            return $data;
        }

        $item = $this->itemProvider->provide($operation, $uriVariables, $context);
        if ($item instanceof Engagement) {
            $this->enrich([$item]);
        }

        return $item;
    }

    /**
     * @param list<Engagement> $engagements
     */
    private function enrich(array $engagements): void
    {
        $budgeted = array_values(array_filter(
            $engagements,
            static fn (Engagement $e): bool => null !== $e->getBudgetType(),
        ));
        if ([] === $budgeted) {
            return;
        }

        $spent = $this->calculator->spentByEngagement($budgeted);
        foreach ($budgeted as $engagement) {
            $row = $spent[(string) $engagement->getId()] ?? ['seconds' => 0, 'fees' => 0];
            $engagement->setBudgetSpent(
                Engagement::BUDGET_HOURS === $engagement->getBudgetType()
                    ? intdiv($row['seconds'], 60)
                    : $row['fees'],
            );
        }
    }
}
