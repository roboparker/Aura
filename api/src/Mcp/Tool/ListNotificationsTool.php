<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Notification;
use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Reads the caller's own notification inbox. Scoping is trivially safe here —
 * a notification belongs to exactly one recipient, so the query filters on the
 * authenticated user and there is no space permission to consult.
 */
final class ListNotificationsTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'list_notifications';
    }

    public function getDescription(): string
    {
        return 'List the user\'s notifications, newest first — mentions, replies, assignments, reminders and alerts. Defaults to unread and unarchived. Each carries a target link so you can follow up on what it refers to.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'unreadOnly' => ['type' => 'boolean', 'description' => 'Only unread notifications. Defaults to true.'],
                'includeArchived' => ['type' => 'boolean', 'description' => 'Include archived ones. Defaults to false.'],
                'type' => [
                    'type' => 'string',
                    'enum' => Notification::TYPES,
                    'description' => 'Only notifications of this type.',
                ],
                'limit' => ['type' => 'integer', 'description' => 'Maximum to return (1-100, default 50).'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $qb = $this->em->getRepository(Notification::class)
            ->createQueryBuilder('n')
            ->where('n.recipient = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC');

        if (false !== ($arguments['unreadOnly'] ?? true)) {
            $qb->andWhere('n.readAt IS NULL');
        }
        if (true !== ($arguments['includeArchived'] ?? false)) {
            $qb->andWhere('n.archivedAt IS NULL');
        }

        $type = $this->input->optionalString($arguments, 'type');
        if (null !== $type) {
            $qb->andWhere('n.type = :type')->setParameter('type', $type);
        }

        $limit = $this->input->optionalInt($arguments, 'limit') ?? 50;
        $qb->setMaxResults(max(1, min(100, $limit)));

        return [
            'items' => array_map(
                fn (Notification $notification) => $this->serializer->notification($notification),
                $qb->getQuery()->getResult(),
            ),
        ];
    }
}
