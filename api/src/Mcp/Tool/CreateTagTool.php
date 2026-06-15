<?php

namespace App\Mcp\Tool;

use App\Entity\Tag;
use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates a tag owned by the caller, mirroring the REST `Post` +
 * TagOwnerProcessor (owner is stamped server-side).
 */
final class CreateTagTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'create_tag';
    }

    public function getDescription(): string
    {
        return 'Create a tag owned by the authenticated user. Color is a #RRGGBB hex (defaults to grey).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Tag label (required).'],
                'color' => ['type' => 'string', 'description' => 'Hex color like #22c55e. Defaults to #6b7280.'],
                'description' => ['type' => 'string', 'description' => 'Optional note.'],
            ],
            'required' => ['title'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $tag = new Tag();
        $tag->setOwner($user);
        $tag->setTitle($this->input->requireString($arguments, 'title'));
        $color = $this->input->optionalString($arguments, 'color');
        if (null !== $color) {
            $tag->setColor($color);
        }
        $description = $this->input->optionalString($arguments, 'description');
        if (null !== $description) {
            $tag->setDescription($description);
        }

        $this->input->assertValid($tag);
        $this->em->persist($tag);
        $this->em->flush();

        return $this->serializer->tag($tag);
    }
}
