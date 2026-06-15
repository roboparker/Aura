<?php

namespace App\Mcp\Tool;

use App\Entity\Comment;
use App\Entity\Discussion;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

final class ListDiscussionCommentsTool implements McpToolInterface
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
        return 'list_discussion_comments';
    }

    public function getDescription(): string
    {
        return 'List replies on a discussion thread in chronological order, paginated. Comments are flat — no reply tree — and ordered by createdAt ascending.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'discussionId' => ['type' => 'string'],
                'page' => ['type' => 'integer', 'minimum' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
            'required' => ['discussionId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $discussionId = $this->input->requireUuid('discussionId', $arguments['discussionId'] ?? null);
        $discussion = $this->em->getRepository(Discussion::class)->find($discussionId);
        if (null === $discussion || !$this->authz->canReadDiscussion($discussion, $user)) {
            throw McpException::notFound(sprintf('Discussion %s', $discussionId));
        }

        ['page' => $page, 'limit' => $limit] = $this->input->pagination($arguments);

        $qb = $this->em->getRepository(Comment::class)
            ->createQueryBuilder('c')
            ->where('c.discussion = :discussion')
            ->setParameter('discussion', $discussion)
            ->orderBy('c.createdAt', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
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
            'page' => $page,
            'limit' => $limit,
        ];
    }
}
