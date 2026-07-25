<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Repository\TimeEntryRepository;
use App\Service\TimeEntryGuard;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stops the caller's running timer. Takes no arguments — a user has at most one
 * running timer, so asking the model to identify it would only invite a wrong id.
 */
final class StopTimerTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private TimeEntryRepository $timeEntries,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
        private TimeEntryGuard $guard,
    ) {
    }

    public function getName(): string
    {
        return 'stop_timer';
    }

    public function getDescription(): string
    {
        return 'Stop the user\'s currently running timer and return the completed entry. Errors if no timer is running.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $entry = $this->timeEntries->findRunningForUser($user);
        if (null === $entry) {
            throw McpException::invalidInput('No timer is currently running. Use start_timer to begin one.');
        }

        if ($this->guard->weekIsLocked($entry)) {
            throw McpException::invalidInput(TimeEntryGuard::WEEK_LOCKED_MESSAGE);
        }

        $entry->setEndedAt(new \DateTimeImmutable());

        // A timer started before midnight would otherwise trip the entity's
        // same-day rule on save. Say so plainly instead of surfacing a
        // validation error the model can't act on.
        $startedAt = $entry->getStartedAt();
        if (null !== $startedAt && $startedAt->format('Y-m-d') !== $entry->getEndedAt()?->format('Y-m-d')) {
            throw McpException::invalidInput(
                'This timer has been running since ' . $startedAt->format('Y-m-d')
                . ' and would span more than one day. Edit it in the web app and log the remainder separately.',
            );
        }

        $this->input->assertValid($entry);
        $this->em->flush();

        return $this->serializer->timeEntry($entry);
    }
}
