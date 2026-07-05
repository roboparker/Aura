<?php

namespace App\Mcp\Tool;

use App\Entity\Board;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

final class DeleteBoardTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpAuthorization $authz,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'delete_project';
    }

    public function getDescription(): string
    {
        return 'Permanently delete a board and detach its tasks (tasks survive — `board` becomes null on each, mirroring the PWA delete).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'boardId' => ['type' => 'string'],
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
        $this->em->remove($board);
        $this->em->flush();
        return ['deleted' => true, 'boardId' => (string) $boardId];
    }
}
