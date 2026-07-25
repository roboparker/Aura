<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Project;
use App\Entity\User;
use App\Mcp\McpBillingAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Security\Permission\SpacePermission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The picker `log_time` depends on: without a project id and a category id
 * there is nothing to log time against, so this tool returns both in one call
 * rather than making the model chain two lookups.
 */
final class ListProjectsTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpBillingAuthorization $authz,
        private McpEntitySerializer $serializer,
    ) {
    }

    public function getName(): string
    {
        return 'list_projects';
    }

    public function getDescription(): string
    {
        return 'List client projects the user can track time against, each with its categories (the category fixes the hourly rate). Note: these are billing projects for time tracking and invoicing — task boards are a separate concept, see list_boards.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'spaceId' => ['type' => 'string', 'description' => 'Only return projects in this space.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $spaces = $this->authz->readableSpaces($user, SpacePermission::TIME_ENTRIES);
        if ([] === $spaces) {
            return ['items' => []];
        }

        $qb = $this->em->getRepository(Project::class)
            ->createQueryBuilder('p')
            ->where('p.space IN (:spaces)')
            ->setParameter('spaces', $spaces)
            ->orderBy('p.name', 'ASC');

        $spaceId = $arguments['spaceId'] ?? null;
        if (is_string($spaceId) && '' !== $spaceId) {
            $qb->andWhere('p.space = :spaceId')->setParameter('spaceId', $spaceId);
        }

        return [
            'items' => array_map(
                fn (Project $project) => $this->serializer->project($project),
                $qb->getQuery()->getResult(),
            ),
        ];
    }
}
