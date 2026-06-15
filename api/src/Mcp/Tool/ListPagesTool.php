<?php

namespace App\Mcp\Tool;

use App\Doctrine\SpaceMembershipDql;
use App\Entity\Page;
use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Lists pages the caller can see (scoped to their space memberships),
 * with optional filters by space, parent, and a text query over the
 * title/body. Ordered top-level first, then by sort position.
 */
final class ListPagesTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'list_pages';
    }

    public function getDescription(): string
    {
        return 'List pages the user can access, newest-tree-first, paginated. Filter by spaceId, by parentId (omit to include all levels), or by a search string matched against title and body.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'spaceId' => ['type' => 'string', 'description' => 'Restrict to one space.'],
                'parentId' => ['type' => 'string', 'description' => 'Restrict to the direct children of this page.'],
                'search' => ['type' => 'string', 'description' => 'Case-insensitive match on title or body.'],
                'page' => ['type' => 'integer', 'minimum' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        ['page' => $page, 'limit' => $limit] = $this->input->pagination($arguments);

        $qb = $this->em->getRepository(Page::class)
            ->createQueryBuilder('p')
            ->where(SpaceMembershipDql::userBelongsToProjectSpace('p', 'page_list'))
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $spaceId = $this->input->optionalUuid('spaceId', $arguments['spaceId'] ?? null);
        if (null !== $spaceId) {
            $qb->andWhere('p.space = :spaceId')->setParameter('spaceId', $spaceId);
        }
        $parentId = $this->input->optionalUuid('parentId', $arguments['parentId'] ?? null);
        if (null !== $parentId) {
            $qb->andWhere('p.parent = :parentId')->setParameter('parentId', $parentId);
        }
        $search = $this->input->optionalString($arguments, 'search');
        if (null !== $search && '' !== trim($search)) {
            $qb->andWhere('(LOWER(p.title) LIKE :q OR LOWER(p.body) LIKE :q)')
                ->setParameter('q', '%' . strtolower(trim($search)) . '%');
        }

        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);
        $items = [];
        foreach ($paginator as $row) {
            /** @var Page $row */
            $items[] = $this->serializer->page($row);
        }

        return [
            'items' => $items,
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ];
    }
}
