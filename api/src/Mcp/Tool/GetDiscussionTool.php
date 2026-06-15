<?php

namespace App\Mcp\Tool;

use App\Entity\Discussion;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

final class GetDiscussionTool implements McpToolInterface
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
        return 'get_discussion';
    }

    public function getDescription(): string
    {
        return 'Fetch one discussion thread by id. Returns 404 when the user is not a member of the discussion\'s space.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'discussionId' => ['type' => 'string', 'description' => 'UUID of the discussion.'],
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

        return $this->serializer->discussion($discussion);
    }
}
