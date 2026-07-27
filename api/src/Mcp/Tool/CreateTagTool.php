<?php

namespace App\Mcp\Tool;

use App\Entity\Tag;
use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpInputHelper;
use App\Mcp\McpSpaceResolver;
use App\Service\AvatarColorService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates a tag in a space (#tags-space). The owner is stamped server-side;
 * the space defaults to the caller's personal space when no spaceId is given,
 * mirroring create_page.
 */
final class CreateTagTool implements McpToolInterface
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
        return 'create_tag';
    }

    public function getDescription(): string
    {
        return 'Create a tag in a space. Pass a spaceId (from list_spaces) for a shared space, or omit it for your personal space. Color must be one of the supported palette values (defaults to slate).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Tag label (required).'],
                'spaceId' => ['type' => 'string', 'description' => 'UUID of a space the user belongs to. Omit for the personal space.'],
                // Enumerated rather than free-form: the entity constrains this
                // to the WCAG-AA palette, so advertising an arbitrary hex would
                // just produce validation errors the model has to guess its
                // way out of.
                'color' => [
                    'type' => 'string',
                    'enum' => AvatarColorService::PALETTE,
                    'description' => 'Tag color. Must be one of the listed palette values. Defaults to #334155 (slate).',
                ],
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
        $tag->setSpace($this->spaces->resolveMemberSpace($arguments['spaceId'] ?? null, $user));
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
