<?php

namespace App\Mcp\Tool;

use App\Entity\Board;
use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

final class ListBoardsTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'list_boards';
    }

    public function getDescription(): string
    {
        return 'List boards the user is a member of, paginated and ordered newest first. Each entry includes the member list and a count of attached tasks.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page' => ['type' => 'integer', 'minimum' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        ['page' => $page, 'limit' => $limit] = $this->input->pagination($arguments);

        $qb = $this->em->getRepository(Board::class)
            ->createQueryBuilder('p')
            ->where(\App\Doctrine\SpaceMembershipDql::userBelongsToProjectSpace('p', 'list_boards'))
            ->setParameter('user', $user)
            ->orderBy('p.createdOn', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: true);
        $items = [];
        foreach ($paginator as $board) {
            /** @var Board $board */
            $items[] = $this->serializer->board($board);
        }

        return [
            'items' => $items,
            'total' => count($paginator),
            'page' => $page,
            'limit' => $limit,
        ];
    }
}
