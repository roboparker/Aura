<?php

namespace App\Mcp\Tool;

use App\Entity\Board;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateBoardTool implements McpToolInterface
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
        return 'update_board';
    }

    public function getDescription(): string
    {
        return 'Update a board\'s title or description. Any board member may rename — same as the PWA.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'boardId' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
            ],
            'required' => ['boardId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $boardId = $this->input->requireUuid('boardId', $arguments['boardId'] ?? null);
        $board = $this->em->getRepository(Board::class)->find($boardId);
        if (null === $board || !$this->authz->canEditProject($board, $user)) {
            throw McpException::notFound(sprintf('Board %s', $boardId));
        }

        if (array_key_exists('title', $arguments)) {
            $board->setTitle($this->input->requireString($arguments, 'title'));
        }
        if (array_key_exists('description', $arguments)) {
            $board->setDescription($this->input->optionalString($arguments, 'description'));
        }

        $this->input->assertValid($board);
        $this->em->flush();

        return $this->serializer->board($board);
    }
}
