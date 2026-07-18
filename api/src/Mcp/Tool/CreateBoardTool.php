<?php

namespace App\Mcp\Tool;

use App\Entity\Board;
use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpInputHelper;
use App\Mcp\McpSpaceResolver;
use Doctrine\ORM\EntityManagerInterface;

final class CreateBoardTool implements McpToolInterface
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
        return 'create_board';
    }

    public function getDescription(): string
    {
        return 'Create a new board. The authenticated user becomes the owner. Pass a spaceId (from list_spaces) to create it in a shared space; omit it to use your personal space. Members come from the space, not the board.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Board title (required).'],
                'description' => ['type' => 'string', 'description' => 'Optional Markdown description.'],
                'spaceId' => ['type' => 'string', 'description' => 'UUID of a space the user belongs to. Omit for the personal space.'],
            ],
            'required' => ['title'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $title = $this->input->requireString($arguments, 'title');
        $description = $this->input->optionalString($arguments, 'description');
        // An explicit space must be one the caller belongs to; omitted
        // leaves space null so BoardSpaceDefaultListener defaults it to
        // the caller's personal space (where they're admin) at persist.
        $space = $this->spaces->resolveMemberSpaceOrNull($arguments['spaceId'] ?? null, $user);

        $board = new Board();
        $board->setOwner($user);
        if (null !== $space) {
            $board->setSpace($space);
        }
        $board->setTitle($title);
        if (null !== $description) {
            $board->setDescription($description);
        }

        $this->input->assertValid($board);
        $this->em->persist($board);
        $this->em->flush();

        return $this->serializer->board($board, taskCount: 0, openTaskCount: 0);
    }
}
