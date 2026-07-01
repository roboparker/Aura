<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\GlobalCustomFieldDefinition;
use App\Entity\Project;
use App\Repository\ProjectFieldVisibilityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The global-field sibling of {@see CustomFieldVisibilityProvider}: decorates
 * the GlobalCustomFieldDefinition collection provider to inject each field's
 * PER-PROJECT visibility (#custom-fields-project / #global-custom-fields) when
 * the collection is fetched in a project context (`?projects={iri}`).
 *
 * Global definitions are admin-managed and their own `visibility` column is
 * only the cross-project default; a {@see \App\Entity\ProjectFieldVisibility}
 * row (its polymorphic global arm) lets a project member place the field on
 * the list / board without touching the shared definition.
 *
 * @implements ProviderInterface<GlobalCustomFieldDefinition>
 */
final class GlobalCustomFieldVisibilityProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<GlobalCustomFieldDefinition> $inner
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.collection_provider')]
        private readonly ProviderInterface $inner,
        private readonly EntityManagerInterface $em,
        private readonly ProjectFieldVisibilityRepository $overrides,
    ) {
    }

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $result = $this->inner->provide($operation, $uriVariables, $context);

        $project = $this->resolveProject($context);
        if (null !== $project && is_iterable($result)) {
            $map = $this->overrides->visibilityMapForProject($project);
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
     * Resolve a single-project context from the `?projects={iri}` filter.
     *
     * @param array<string, mixed> $context
     */
    private function resolveProject(array $context): ?Project
    {
        $filters = $context['filters'] ?? null;
        $raw = is_array($filters) ? ($filters['projects'] ?? null) : null;
        if (!is_string($raw) || '' === $raw) {
            return null;
        }

        $segment = substr($raw, (int) strrpos($raw, '/') + 1);
        if (!Uuid::isValid($segment)) {
            return null;
        }

        return $this->em->getRepository(Project::class)->find($segment);
    }
}
