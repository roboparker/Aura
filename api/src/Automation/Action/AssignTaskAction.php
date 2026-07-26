<?php

declare(strict_types=1);

namespace App\Automation\Action;

use App\Automation\ActionDefinitionInterface;
use App\Automation\AutomationContext;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Puts someone on the task.
 *
 * **Confined to the board's space.** The config carries a user id, and a rule
 * naming somebody outside the space would otherwise assign work to a stranger
 * — so an unresolvable or non-member id throws rather than being skipped
 * quietly, and the run log shows why.
 */
final class AssignTaskAction implements ActionDefinitionInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function type(): string
    {
        return 'task.assign';
    }

    public function label(): string
    {
        return 'Assign to someone';
    }

    public function description(): string
    {
        return 'Adds a member of this space as an assignee.';
    }

    public function configSchema(): array
    {
        return [
            ['key' => 'user', 'type' => 'space_member', 'label' => 'Assign to'],
        ];
    }

    public function validateConfig(array $config): ?string
    {
        $user = $config['user'] ?? null;

        return is_string($user) && '' !== $user ? null : 'Choose who to assign.';
    }

    public function execute(AutomationContext $context, array $config): string
    {
        $userId = $config['user'] ?? null;
        if (!is_string($userId) || '' === $userId) {
            throw new \RuntimeException('No assignee configured.');
        }

        $user = $this->em->getRepository(User::class)->find($userId);
        if (!$user instanceof User) {
            throw new \RuntimeException('That person no longer exists.');
        }

        $space = $context->task->getBoard()?->getSpace();
        if (null === $space || !$space->hasMember($user)) {
            // Fail closed: a rule must not reach outside its own space.
            throw new \RuntimeException('That person is not a member of this space.');
        }

        if ($context->task->getAssignees()->contains($user)) {
            return sprintf('%s was already assigned', $user->getEmail());
        }

        $context->task->addAssignee($user);

        return sprintf('assigned to %s', $user->getEmail());
    }
}
