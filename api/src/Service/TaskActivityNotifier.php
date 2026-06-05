<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Task;
use App\Entity\User;

/**
 * Task-side notifications: someone being added as an assignee
 * (`assigned`) and a task changing state (`status`, currently the
 * completion transition). Both the task create processor (POST — every
 * assignee is new) and the update processor (PATCH — the newly-added
 * subset) feed `notifyAssigned`.
 */
final class TaskActivityNotifier
{
    public function __construct(private NotificationDispatcher $dispatcher)
    {
    }

    /**
     * Notify each newly-added assignee that the task landed on their
     * plate. The actor (the assigner) is skipped by the dispatcher if
     * they assigned themselves.
     *
     * @param iterable<User> $newAssignees
     */
    public function notifyAssigned(Task $task, User $actor, iterable $newAssignees): void
    {
        $title = sprintf('%s assigned you "%s"', $this->displayName($actor), $task->getTitle());

        foreach ($newAssignees as $assignee) {
            $this->dispatcher->notify(
                recipient: $assignee,
                type: Notification::TYPE_ASSIGNED,
                actor: $actor,
                title: $title,
                task: $task,
            );
        }
    }

    /**
     * Notify the task owner + assignees that it was completed. The actor
     * (whoever completed it) is skipped by the dispatcher.
     */
    public function notifyCompleted(Task $task, User $actor): void
    {
        $title = sprintf('%s completed "%s"', $this->displayName($actor), $task->getTitle());

        foreach ($this->stakeholders($task) as $user) {
            $this->dispatcher->notify(
                recipient: $user,
                type: Notification::TYPE_STATUS,
                actor: $actor,
                title: $title,
                task: $task,
            );
        }
    }

    /**
     * Owner + assignees, deduped by id.
     *
     * @return array<string, User>
     */
    private function stakeholders(Task $task): array
    {
        $bag = [];
        $owner = $task->getOwner();
        if (null !== $owner && null !== $owner->getId()) {
            $bag[(string) $owner->getId()] = $owner;
        }
        foreach ($task->getAssignees() as $assignee) {
            if (null !== $assignee->getId()) {
                $bag[(string) $assignee->getId()] = $assignee;
            }
        }

        return $bag;
    }

    private function displayName(User $user): string
    {
        $name = trim($user->getGivenName() . ' ' . $user->getFamilyName());

        return '' !== $name ? $name : $user->getEmail();
    }
}
