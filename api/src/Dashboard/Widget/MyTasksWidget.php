<?php

declare(strict_types=1);

namespace App\Dashboard\Widget;

use App\Dashboard\WidgetDefinitionInterface;
use App\Entity\Space;
use App\Entity\Task;
use App\Entity\User;
use App\Security\Permission\SpacePermission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The viewer's own open tasks in this space.
 *
 * Per-viewer rather than per-widget: the layout is shared, but this one shows
 * each person their own work, so a single placed widget is useful to everybody
 * instead of showing the whole team one person's list. That's why {@see data()}
 * takes the user — a widget's config says what to show, the caller says whose.
 */
final class MyTasksWidget implements WidgetDefinitionInterface
{
    private const MAX_ROWS = 25;

    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function type(): string
    {
        return 'tasks.mine';
    }

    public function name(): string
    {
        return 'My tasks';
    }

    public function description(): string
    {
        return "Your own open tasks in this space — each person sees their own.";
    }

    public function group(): string
    {
        return 'Work';
    }

    public function permissionCategory(array $config = []): string
    {
        return SpacePermission::TASKS;
    }

    public function defaultSize(): array
    {
        return ['w' => 2, 'h' => 2];
    }

    public function configSchema(): array
    {
        return [
            [
                'key' => 'dueOnly',
                'type' => 'boolean',
                'label' => 'Only tasks with a due date',
            ],
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

        if (array_key_exists('dueOnly', $config) && !is_bool($config['dueOnly'])) {
            return 'Only tasks with a due date must be true or false.';
        }

        return null;
    }

    public function data(Space $space, array $config, User $user): array
    {
        $limit = is_int($config['limit'] ?? null) ? max(1, min(self::MAX_ROWS, $config['limit'])) : 8;

        $qb = $this->em->getRepository(Task::class)
            ->createQueryBuilder('t')
            ->leftJoin('t.board', 'b')
            // Tasks reach a space through their board; a standalone task has no
            // board and belongs to nobody's space dashboard.
            ->where('b.space = :space')
            ->andWhere('t.completedOn IS NULL')
            ->andWhere('(t.owner = :user OR :user MEMBER OF t.assignees)')
            ->setParameter('space', $space)
            ->setParameter('user', $user)
            // Undated tasks last, so the list leads with what's actually due.
            ->orderBy('CASE WHEN t.dueDate IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('t.dueDate', 'ASC')
            ->setMaxResults($limit);

        if (true === ($config['dueOnly'] ?? false)) {
            $qb->andWhere('t.dueDate IS NOT NULL');
        }

        $today = new \DateTimeImmutable('today');
        $rows = [];
        /** @var Task $task */
        foreach ($qb->getQuery()->getResult() as $task) {
            $due = $task->getDueDate();
            $rows[] = [
                'id' => (string) $task->getId(),
                'title' => $task->getTitle(),
                'dueDate' => $due?->format('Y-m-d'),
                'overdue' => null !== $due && $due < $today,
                'board' => $task->getBoard()?->getTitle(),
                'boardId' => (string) $task->getBoard()?->getId(),
            ];
        }

        return ['rows' => $rows];
    }
}
