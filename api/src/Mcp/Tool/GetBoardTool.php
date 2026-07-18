<?php

namespace App\Mcp\Tool;

use App\Entity\Board;
use App\Entity\Task;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

final class GetBoardTool implements McpToolInterface
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
        return 'get_board';
    }

    public function getDescription(): string
    {
        return 'Fetch one board by id, including the member list and total/open task counts. Returns 404 when the user is not a member.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'boardId' => ['type' => 'string', 'description' => 'UUID of the board.'],
            ],
            'required' => ['boardId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $boardId = $this->input->requireUuid('boardId', $arguments['boardId'] ?? null);
        $board = $this->em->getRepository(Board::class)->find($boardId);
        if (null === $board || !$this->authz->canReadBoard($board, $user)) {
            throw McpException::notFound(sprintf('Board %s', $boardId));
        }

        $taskRepo = $this->em->getRepository(Task::class);
        $taskCount = (int) $taskRepo->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.board = :board')
            ->setParameter('board', $board)
            ->getQuery()
            ->getSingleScalarResult();
        $openTaskCount = (int) $taskRepo->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.board = :board AND t.completedOn IS NULL')
            ->setParameter('board', $board)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->serializer->board($board, taskCount: $taskCount, openTaskCount: $openTaskCount);
    }
}
