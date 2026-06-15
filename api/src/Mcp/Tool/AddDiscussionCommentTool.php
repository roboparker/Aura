<?php

namespace App\Mcp\Tool;

use App\Entity\Comment;
use App\Entity\Discussion;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Service\CommentMentionService;
use Doctrine\ORM\EntityManagerInterface;

final class AddDiscussionCommentTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpAuthorization $authz,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
        private CommentMentionService $mentions,
    ) {
    }

    public function getName(): string
    {
        return 'add_discussion_comment';
    }

    public function getDescription(): string
    {
        return 'Reply to a discussion thread with a Markdown comment. Threads are flat, ordered chronologically. @mentions notify space members. Locked threads reject new comments.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'discussionId' => ['type' => 'string'],
                'body' => ['type' => 'string', 'description' => 'Markdown body (max 50000 chars).'],
            ],
            'required' => ['discussionId', 'body'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $discussionId = $this->input->requireUuid('discussionId', $arguments['discussionId'] ?? null);
        $body = $this->input->requireString($arguments, 'body');

        $discussion = $this->em->getRepository(Discussion::class)->find($discussionId);
        if (null === $discussion || !$this->authz->canReadDiscussion($discussion, $user)) {
            throw McpException::notFound(sprintf('Discussion %s', $discussionId));
        }

        $comment = new Comment();
        $comment->setDiscussion($discussion);
        $comment->setAuthor($user);
        $comment->setBody($body);

        // assertValid enforces Comment::validateNotLocked — a locked thread
        // rejects new comments here just like the REST POST /comments path.
        $this->input->assertValid($comment);
        $this->em->persist($comment);
        $this->em->flush();

        $this->mentions->dispatchMentions($comment);

        return $this->serializer->comment($comment);
    }
}
