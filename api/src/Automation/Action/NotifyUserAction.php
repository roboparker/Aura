<?php

declare(strict_types=1);

namespace App\Automation\Action;

use App\Automation\ActionDefinitionInterface;
use App\Automation\AutomationContext;
use App\Automation\SurvivesTaskDeletion;
use App\Entity\Notification;
use App\Entity\Space;
use App\Entity\User;
use App\Service\NotificationDispatcher;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sends someone an in-app notification.
 *
 * The only action that still means something once its task is gone, which is
 * what makes `task.deleted` a usable trigger at all — every other action edits
 * the task, and there's nothing left to edit. Hence
 * {@see SurvivesTaskDeletion}.
 *
 * Useful on every other event too: "tell the lead when anything lands in
 * Blocked" doesn't need to touch the task.
 */
final class NotifyUserAction implements ActionDefinitionInterface, SurvivesTaskDeletion
{
    private const MAX_MESSAGE = 500;

    public function __construct(
        private EntityManagerInterface $em,
        private NotificationDispatcher $notifications,
    ) {
    }

    public function type(): string
    {
        return 'task.notify';
    }

    public function label(): string
    {
        return 'Notify someone';
    }

    public function description(): string
    {
        return 'Sends a person an in-app notification. Works even when the task has been deleted.';
    }

    public function configSchema(): array
    {
        return [
            [
                'key' => 'user',
                'type' => 'space_member',
                'label' => 'Notify',
            ],
            [
                'key' => 'message',
                'type' => 'textarea',
                'label' => 'Message',
                'placeholder' => 'What should they be told?',
            ],
        ];
    }

    public function validateConfig(array $config): ?string
    {
        $user = $config['user'] ?? null;
        if (!is_string($user) || '' === trim($user)) {
            return 'Choose who to notify.';
        }

        $message = $config['message'] ?? '';
        if (!is_string($message)) {
            return 'The message must be text.';
        }
        if (mb_strlen($message) > self::MAX_MESSAGE) {
            return sprintf('The message cannot be longer than %d characters.', self::MAX_MESSAGE);
        }

        return null;
    }

    public function execute(AutomationContext $context, array $config): string
    {
        $recipientId = is_string($config['user'] ?? null) ? $config['user'] : '';
        $recipient = $this->em->getRepository(User::class)->find($recipientId);

        if (!$recipient instanceof User) {
            // Fail closed and loudly: the run log is the only place anyone
            // would notice a rule quietly notifying nobody.
            throw new \RuntimeException('The person this rule notifies no longer exists.');
        }

        // Confine to the board's space, exactly like the assign action. A
        // config id is user input, and without this a rule could be pointed at
        // a stranger who'd receive notifications about work they can't see.
        $space = $context->task->getBoard()?->getSpace();
        if (!$space instanceof Space || !$space->hasMember($recipient)) {
            throw new \RuntimeException('That person is not a member of this space.');
        }

        $message = is_string($config['message'] ?? null) ? trim($config['message']) : '';
        if ('' === $message) {
            $message = sprintf('The rule "%s" ran.', $context->automation?->getName() ?? 'Automation');
        }

        $this->notifications->notify(
            recipient: $recipient,
            type: Notification::TYPE_STATUS,
            actor: $context->actor,
            title: $context->task->getTitle(),
            body: $message,
            // A deleted task can't be linked to — passing it would produce a
            // notification whose link 404s the moment it's clicked.
            task: $context->taskDeleted ? null : $context->task,
        );

        return sprintf('notified %s', $recipient->getEmail());
    }
}
