<?php

declare(strict_types=1);

namespace App\Dashboard\Widget;

use App\Dashboard\WidgetDefinitionInterface;
use App\Entity\Board;
use App\Entity\Space;
use App\Entity\Task;
use App\Entity\User;
use App\Security\Permission\SpacePermission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The space's boards with their open-task counts — a jumping-off point from the
 * dashboard into the work itself.
 *
 * Counts come from one grouped query rather than a count per board, so adding a
 * board doesn't add a query.
 */
final class BoardsWidget implements WidgetDefinitionInterface
{
    private const MAX_ROWS = 20;

    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function type(): string
    {
        return 'boards.list';
    }

    public function name(): string
    {
        return 'Boards';
    }

    public function description(): string
    {
        return 'Boards in this space, with how many tasks are still open on each.';
    }

    public function group(): string
    {
        return 'Work';
    }

    public function permissionCategory(array $config = []): string
    {
        return SpacePermission::BOARDS;
    }

    public function defaultSize(): array
    {
        return ['w' => 1, 'h' => 2];
    }

    public function configSchema(): array
    {
        return [
            [
                'key' => 'limit',
                'type' => 'number',
                'label' => 'Rows',
                'min' => 1,
                'max' => self::MAX_ROWS,
            ],
        ];
    }

    public function validateConfig(array $config): ?string
    {
        $limit = $config['limit'] ?? 8;
        if (!is_int($limit) || $limit < 1 || $limit > self::MAX_ROWS) {
            return sprintf('Rows must be a number between 1 and %d.', self::MAX_ROWS);
        }

        return null;
    }

    public function data(Space $space, array $config, User $user): array
    {
        $limit = is_int($config['limit'] ?? null) ? max(1, min(self::MAX_ROWS, $config['limit'])) : 8;

        /** @var list<Board> $boards */
        $boards = $this->em->getRepository(Board::class)
            ->createQueryBuilder('b')
            ->where('b.space = :space')
            ->setParameter('space', $space)
            ->orderBy('b.title', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        if ([] === $boards) {
            return ['rows' => []];
        }

        // One grouped count for the whole page of boards, not one per board.
        /** @var list<array{bid: string|null, openCount: int<0, max>}> $counts */
        $counts = $this->em->createQueryBuilder()
            ->select('IDENTITY(t.board) AS bid', 'COUNT(t.id) AS openCount')
            ->from(Task::class, 't')
            ->where('t.board IN (:boards)')
            ->andWhere('t.completedOn IS NULL')
            ->setParameter('boards', $boards)
            ->groupBy('t.board')
            ->getQuery()
            ->getResult();

        $openByBoard = [];
        foreach ($counts as $row) {
            if (null !== $row['bid']) {
                $openByBoard[$row['bid']] = $row['openCount'];
            }
        }

        $rows = [];
        foreach ($boards as $board) {
            $id = (string) $board->getId();
            $rows[] = [
                'id' => $id,
                'title' => $board->getTitle(),
                'openTasks' => $openByBoard[$id] ?? 0,
            ];
        }

        return ['rows' => $rows];
    }
}
