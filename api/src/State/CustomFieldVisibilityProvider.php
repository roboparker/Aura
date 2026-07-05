<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\CustomFieldDefinition;
use App\Entity\Board;
use App\Repository\BoardFieldVisibilityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Decorates the CustomFieldDefinition collection provider to inject each
 * field's PER-PROJECT visibility (#custom-fields-board) when the collection
 * is fetched in a board context (`?boards={iri}`).
 *
 * Visibility lives on the (board, definition) pair via {@see
 * BoardFieldVisibility}; the definition's own column is only the default when
 * no override row exists. Outside a single-board context (e.g. the
 * space-level `?space=` listing) nothing is injected and the default stands.
 *
 * @implements ProviderInterface<CustomFieldDefinition>
 */
final class CustomFieldVisibilityProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<CustomFieldDefinition> $inner
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private readonly ProviderInterface $inner,
        private readonly EntityManagerInterface $em,
        private readonly BoardFieldVisibilityRepository $overrides,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $result = $this->inner->provide($operation, $uriVariables, $context);

        $board = $this->resolveProject($context);
        if (null !== $board && is_iterable($result)) {
            $map = $this->overrides->visibilityMapForProject($board);
            foreach ($result as $definition) {
                $override = $map[(string) $definition->getId()] ?? null;
                if (null !== $override) {
                    $definition->setVisibility($override);
                }
            }
        }

        return $result;
    }

    /**
     * Resolve a single-board context from the `?boards={iri}` filter.
     *
     * @param array<string, mixed> $context
     */
    private function resolveProject(array $context): ?Board
    {
        $filters = $context['filters'] ?? null;
        $raw = is_array($filters) ? ($filters['boards'] ?? null) : null;
        if (!is_string($raw) || '' === $raw) {
            return null;
        }

        $segment = substr($raw, (int) strrpos($raw, '/') + 1);
        if (!Uuid::isValid($segment)) {
            return null;
        }

        return $this->em->getRepository(Board::class)->find($segment);
    }
}
