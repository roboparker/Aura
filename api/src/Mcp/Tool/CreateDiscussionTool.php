<?php

namespace App\Mcp\Tool;

use App\Entity\Discussion;
use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Mcp\McpSpaceResolver;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Starts a discussion thread in a space (#91/#185). The author is
 * stamped server-side; category defaults to "general".
 */
final class CreateDiscussionTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
        private McpSpaceResolver $spaces,
    ) {
    }

    public function getName(): string
    {
        return 'create_discussion';
    }

    public function getDescription(): string
    {
        return 'Start a discussion thread in a space. Pass a spaceId (from list_spaces) for a shared space, or omit it for your personal space. Category is one of: general, ideas, announcements, q-and-a (default general).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Thread title (required).'],
                'body' => ['type' => 'string', 'description' => 'Markdown body (required).'],
                'category' => [
                    'type' => 'string',
                    'enum' => Discussion::ALLOWED_CATEGORIES,
                    'description' => 'Thread category (default general).',
                ],
                'spaceId' => ['type' => 'string', 'description' => 'UUID of a space the user belongs to. Omit for the personal space.'],
            ],
            'required' => ['title', 'body'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $title = $this->input->requireString($arguments, 'title');
        $body = $this->input->requireString($arguments, 'body');
        $space = $this->spaces->resolveMemberSpace($arguments['spaceId'] ?? null, $user);

        $discussion = new Discussion();
        $discussion->setAuthor($user);
        $discussion->setSpace($space);
        $discussion->setTitle($title);
        $discussion->setBody($body);

        $category = $this->input->optionalString($arguments, 'category');
        if (null !== $category) {
            if (!in_array($category, Discussion::ALLOWED_CATEGORIES, true)) {
                throw McpException::invalidInput('category must be one of: ' . implode(', ', Discussion::ALLOWED_CATEGORIES) . '.');
            }
            $discussion->setCategory($category);
        }

        $this->input->assertValid($discussion);
        $this->em->persist($discussion);
        $this->em->flush();

        return $this->serializer->discussion($discussion);
    }
}
