<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\Project;
use App\Entity\Service;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Mcp\McpBillingAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use App\Service\TimeEntryGuard;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Starts a running timer — a time entry with no end. A user can only have one
 * running at a time; starting a second stops the first, which is handled by
 * {@see TimeEntryGuard} so this behaves exactly like the timer in the web UI.
 */
final class StartTimerTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpBillingAuthorization $authz,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
        private TimeEntryGuard $guard,
    ) {
    }

    public function getName(): string
    {
        return 'start_timer';
    }

    public function getDescription(): string
    {
        return 'Start a running timer on a client project. Any timer already running for the user is stopped first. Use stop_timer to finish it, or log_time when the work is already done.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'projectId' => ['type' => 'string', 'description' => 'UUID of the client project (from list_projects).'],
                'categoryId' => ['type' => 'string', 'description' => 'UUID of a category on that project — it sets the hourly rate.'],
                'description' => ['type' => 'string', 'description' => 'What is being worked on.'],
            ],
            'required' => ['projectId', 'categoryId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $projectId = $this->input->requireUuid('projectId', $this->input->requireString($arguments, 'projectId'));
        $categoryId = $this->input->requireUuid('categoryId', $this->input->requireString($arguments, 'categoryId'));

        $project = $this->em->getRepository(Project::class)->find($projectId);
        if (null === $project || !$this->authz->canTrackTimeIn($project->getSpace(), $user)) {
            throw McpException::notFound(sprintf('Project %s', $projectId));
        }

        $category = $this->em->getRepository(Service::class)->find($categoryId);
        if (null === $category || true !== $category->getProject()?->getId()?->equals($project->getId())) {
            throw McpException::notFound(sprintf('Category %s on project %s', $categoryId, $projectId));
        }

        $entry = new TimeEntry();
        $entry->setProject($project);
        $entry->setCategory($category);
        $entry->setSpace($project->getSpace());
        $entry->setStartedAt(new \DateTimeImmutable());

        $description = $this->input->optionalString($arguments, 'description');
        if (null !== $description) {
            $entry->setDescription($description);
        }

        // Stops whatever was already running, and flushes that stop before we
        // insert — the one-running-timer index is partial and can't be deferred.
        $this->guard->prepareCreate($entry, $user);
        if ($this->guard->weekIsLocked($entry)) {
            throw McpException::invalidInput(TimeEntryGuard::WEEK_LOCKED_MESSAGE);
        }

        $this->input->assertValid($entry);
        $this->em->persist($entry);
        $this->em->flush();

        return $this->serializer->timeEntry($entry);
    }
}
