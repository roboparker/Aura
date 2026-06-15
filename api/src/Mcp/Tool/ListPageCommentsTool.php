<?php

namespace App\Mcp\Tool;

use App\Entity\Comment;
use App\Entity\Page;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

final class ListPageCommentsTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpAuthorization $authz,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'list_page_comments';
    }

    public function getDescription(): string
    {
        return 'List comments on a page in chronological order, paginated. Comments are flat — no reply tree — and ordered by createdAt ascending.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => ['type' => 'string'],
                'page' => ['type' => 'integer', 'minimum' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
            'required' => ['pageId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $pageId = $this->input->requireUuid('pageId', $arguments['pageId'] ?? null);
        $page = $this->em->getRepository(Page::class)->find($pageId);
        if (null === $page || !$this->authz->canReadPage($page, $user)) {
            throw McpException::notFound(sprintf('Page %s', $pageId));
        }

        ['page' => $pageNum, 'limit' => $limit] = $this->input->pagination($arguments);

        $qb = $this->em->getRepository(Comment::class)
            ->createQueryBuilder('c')
            ->where('c.page = :page')
            ->setParameter('page', $page)
            ->orderBy('c.createdAt', 'ASC')
            ->setFirstResult(($pageNum - 1) * $limit)
            ->setMaxResults($limit);

        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);
        $items = [];
        foreach ($paginator as $comment) {
            /** @var Comment $comment */
            $items[] = $this->serializer->comment($comment);
        }

        return [
            'items' => $items,
            'total' => count($paginator),
            'page' => $pageNum,
            'limit' => $limit,
        ];
    }
}
