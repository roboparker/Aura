<?php

namespace App\Mcp\Tool;

use App\Doctrine\SpaceMembershipDql;
use App\Entity\Discussion;
use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Lists discussion threads the caller can see (scoped to their space
 * memberships), pinned first then newest. Optional filters by space and
 * category.
 */
final class ListDiscussionsTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'list_discussions';
    }

    public function getDescription(): string
    {
        return 'List discussion threads the user can access, pinned first then newest, paginated. Filter by spaceId or by category (general, ideas, announcements, q-and-a).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'spaceId' => ['type' => 'string', 'description' => 'Restrict to one space.'],
                'category' => [
                    'type' => 'string',
                    'enum' => Discussion::ALLOWED_CATEGORIES,
                    'description' => 'Restrict to one category.',
                ],
                'page' => ['type' => 'integer', 'minimum' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        ['page' => $page, 'limit' => $limit] = $this->input->pagination($arguments);

        $qb = $this->em->getRepository(Discussion::class)
            ->createQueryBuilder('d')
            ->where(SpaceMembershipDql::userBelongsToProjectSpace('d', 'discussion_list'))
            ->setParameter('user', $user)
            ->orderBy('d.isPinned', 'DESC')
            ->addOrderBy('d.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $spaceId = $this->input->optionalUuid('spaceId', $arguments['spaceId'] ?? null);
        if (null !== $spaceId) {
            $qb->andWhere('d.space = :spaceId')->setParameter('spaceId', $spaceId);
        }
        $category = $this->input->optionalString($arguments, 'category');
        if (null !== $category) {
            if (!in_array($category, Discussion::ALLOWED_CATEGORIES, true)) {
                throw McpException::invalidInput('category must be one of: ' . implode(', ', Discussion::ALLOWED_CATEGORIES) . '.');
            }
            $qb->andWhere('d.category = :category')->setParameter('category', $category);
        }

        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);
        $items = [];
        foreach ($paginator as $row) {
            /** @var Discussion $row */
            $items[] = $this->serializer->discussion($row);
        }

        return [
            'items' => $items,
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ];
    }
}
