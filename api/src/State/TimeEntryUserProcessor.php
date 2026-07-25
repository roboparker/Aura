<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\TimeEntry;
use App\Security\AuthenticatedUserResolver;
use App\Service\TimeEntryGuard;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Stamps the current user as the time entry's tracker on create, mirroring the
 * trust model of TaskOwnerProcessor — the owner is
 * set server-side, never from the payload. On update the existing owner is
 * preserved (the processor also runs on Patch), so a member can't reassign a
 * time entry to someone else.
 *
 * The rules themselves live in {@see TimeEntryGuard} so the MCP tools enforce
 * exactly the same ones — tracker stamping, the one-running-timer invariant,
 * the billed-entry freeze, and the submitted-week lock. This processor only
 * translates a refusal into the HTTP-shaped error.
 *
 * @implements ProcessorInterface<TimeEntry, TimeEntry>
 */
final class TimeEntryUserProcessor implements ProcessorInterface
{
    /**
     * @param ProcessorInterface<TimeEntry, TimeEntry> $persistProcessor
     */
    public function __construct(
        #[Autowire(service: 'api_platform.doctrine.orm.state.persist_processor')]
        private ProcessorInterface $persistProcessor,
        private AuthenticatedUserResolver $auth,
        private TimeEntryGuard $guard,
    ) {
    }

    /**
     * @param TimeEntry $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TimeEntry
    {
        $user = $this->auth->requireUser('track time');

        $isCreate = null === $data->getUser();

        if ($isCreate) {
            $this->guard->prepareCreate($data, $user);
        } else {
            $refusal = $this->guard->updateRefusalReason($data);
            if (null !== $refusal) {
                throw new UnprocessableEntityHttpException($refusal);
            }
        }

        // Timesheet approvals (#654): a submitted (pending/approved) week is
        // frozen for the entry's tracker — creates and edits both bounce.
        if ($this->guard->weekIsLocked($data)) {
            throw new UnprocessableEntityHttpException(TimeEntryGuard::WEEK_LOCKED_MESSAGE);
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}
