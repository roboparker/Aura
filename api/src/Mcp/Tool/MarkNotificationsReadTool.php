<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Notification;
use App\Entity\User;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Marks notifications read. Requires either explicit ids or `all: true` — there
 * is deliberately no "mark everything" default, because clearing an inbox the
 * user hasn't seen is destructive in the way that matters (they lose the signal,
 * not the data).
 */
final class MarkNotificationsReadTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'mark_notifications_read';
    }

    public function getDescription(): string
    {
        return 'Mark the user\'s notifications as read — either specific ids, or all unread with all=true. Only ever affects the authenticated user\'s own inbox.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'notificationIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'UUIDs of notifications to mark read.',
                ],
                'all' => ['type' => 'boolean', 'description' => 'Mark every unread notification read.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $ids = $this->input->optionalStringArray($arguments, 'notificationIds') ?? [];
        $all = true === ($arguments['all'] ?? false);

        if (!$all && [] === $ids) {
            throw McpException::invalidInput('Pass notificationIds, or all=true to clear the whole inbox.');
        }
        if ($all && [] !== $ids) {
            throw McpException::invalidInput('Pass either notificationIds or all=true, not both.');
        }

        $qb = $this->em->getRepository(Notification::class)
            ->createQueryBuilder('n')
            ->where('n.recipient = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user);

        if (!$all) {
            $uuids = [];
            foreach ($ids as $index => $rawId) {
                $uuids[] = $this->input->requireUuid(sprintf('notificationIds[%d]', $index), $rawId);
            }
            // Scoped by recipient above, so an id belonging to someone else
            // simply matches nothing rather than erroring — the caller learns
            // only about their own inbox either way.
            $qb->andWhere('n.id IN (:ids)')->setParameter('ids', $uuids);
        }

        /** @var list<Notification> $notifications */
        $notifications = $qb->getQuery()->getResult();

        $now = new \DateTimeImmutable();
        foreach ($notifications as $notification) {
            $notification->setReadAt($now);
        }
        $this->em->flush();

        return ['markedRead' => count($notifications)];
    }
}
