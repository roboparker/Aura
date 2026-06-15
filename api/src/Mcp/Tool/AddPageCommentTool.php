<?php

namespace App\Mcp\Tool;

use App\Entity\Comment;
use App\Entity\Page;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Service\CommentMentionService;
use Doctrine\ORM\EntityManagerInterface;

final class AddPageCommentTool implements McpToolInterface
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
        return 'add_page_comment';
    }

    public function getDescription(): string
    {
        return 'Add a Markdown comment to a page. Threads are flat — every comment is a top-level entry, ordered chronologically. @mentions notify space members.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => ['type' => 'string'],
                'body' => ['type' => 'string', 'description' => 'Markdown body (max 50000 chars).'],
            ],
            'required' => ['pageId', 'body'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $pageId = $this->input->requireUuid('pageId', $arguments['pageId'] ?? null);
        $body = $this->input->requireString($arguments, 'body');

        $page = $this->em->getRepository(Page::class)->find($pageId);
        if (null === $page || !$this->authz->canReadPage($page, $user)) {
            throw McpException::notFound(sprintf('Page %s', $pageId));
        }

        $comment = new Comment();
        $comment->setPage($page);
        $comment->setAuthor($user);
        $comment->setBody($body);

        $this->input->assertValid($comment);
        $this->em->persist($comment);
        $this->em->flush();

        // `@mention` tokens fire notifications post-persist — same path as
        // the HTTP `POST /comments` flow.
        $this->mentions->dispatchMentions($comment);

        return $this->serializer->comment($comment);
    }
}
