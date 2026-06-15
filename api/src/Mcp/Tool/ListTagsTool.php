<?php

namespace App\Mcp\Tool;

use App\Entity\Tag;
use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use App\Repository\TagRepository;

/**
 * Lists the caller's own tags so the model can discover existing tag ids
 * before attaching them to a task (or decide to create a new one).
 */
final class ListTagsTool implements McpToolInterface
{
    public function __construct(
        private TagRepository $tags,
        private McpEntitySerializer $serializer,
    ) {
    }

    public function getName(): string
    {
        return 'list_tags';
    }

    public function getDescription(): string
    {
        return 'List the tags owned by the authenticated user, alphabetically. Use the returned ids as tagIds when creating or updating tasks.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $tags = $this->tags->createQueryBuilder('t')
            ->where('t.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('t.title', 'ASC')
            ->getQuery()
            ->getResult();

        return [
            'items' => array_map(
                fn (Tag $tag) => $this->serializer->tag($tag),
                $tags,
            ),
        ];
    }
}
