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
 * Logs a completed block of time. The rate and billability are derived
 * server-side from the category — never accepted from the caller — so an agent
 * cannot invent a billing rate.
 */
final class LogTimeTool implements McpToolInterface
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
        return 'log_time';
    }

    public function getDescription(): string
    {
        return 'Log a completed block of time against a client project. Give either durationMinutes or an explicit endedAt. The hourly rate comes from the chosen category — it cannot be set here. Call list_projects first for project and category ids.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'projectId' => ['type' => 'string', 'description' => 'UUID of the client project (from list_projects).'],
                'categoryId' => ['type' => 'string', 'description' => 'UUID of a category on that project — it sets the hourly rate.'],
                'startedAt' => ['type' => 'string', 'description' => 'ISO-8601 start datetime.'],
                'durationMinutes' => ['type' => 'integer', 'description' => 'Length in minutes. Alternative to endedAt.'],
                'endedAt' => ['type' => 'string', 'description' => 'ISO-8601 end datetime. Must fall on the same day as startedAt.'],
                'description' => ['type' => 'string', 'description' => 'What the time was spent on.'],
            ],
            'required' => ['projectId', 'categoryId', 'startedAt'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $projectId = $this->input->requireUuid('projectId', $this->input->requireString($arguments, 'projectId'));
        $categoryId = $this->input->requireUuid('categoryId', $this->input->requireString($arguments, 'categoryId'));

        $startedAt = $this->input->optionalDateTime($arguments, 'startedAt');
        if (null === $startedAt) {
            throw McpException::invalidInput('startedAt must be an ISO-8601 datetime.');
        }

        $project = $this->em->getRepository(Project::class)->find($projectId);
        if (null === $project || !$this->authz->canTrackTimeIn($project->getSpace(), $user)) {
            throw McpException::notFound(sprintf('Project %s', $projectId));
        }

        $category = $this->em->getRepository(Service::class)->find($categoryId);
        // The entity validates project-membership too; checking here turns what
        // would be a generic 422 into a message that names the actual problem.
        if (null === $category || true !== $category->getProject()?->getId()?->equals($project->getId())) {
            throw McpException::notFound(sprintf('Category %s on project %s', $categoryId, $projectId));
        }

        $endedAt = $this->resolveEnd($arguments, $startedAt);

        $entry = new TimeEntry();
        $entry->setProject($project);
        $entry->setCategory($category);
        $entry->setSpace($project->getSpace());
        $entry->setStartedAt($startedAt);
        $entry->setEndedAt($endedAt);

        $description = $this->input->optionalString($arguments, 'description');
        if (null !== $description) {
            $entry->setDescription($description);
        }

        $this->guard->prepareCreate($entry, $user);
        if ($this->guard->weekIsLocked($entry)) {
            throw McpException::invalidInput(TimeEntryGuard::WEEK_LOCKED_MESSAGE);
        }

        $this->input->assertValid($entry);
        $this->em->persist($entry);
        $this->em->flush();

        return $this->serializer->timeEntry($entry);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function resolveEnd(array $arguments, \DateTimeImmutable $startedAt): \DateTimeImmutable
    {
        $explicitEnd = $this->input->optionalDateTime($arguments, 'endedAt');
        $minutes = $this->input->optionalInt($arguments, 'durationMinutes');

        if (null !== $explicitEnd && null !== $minutes) {
            throw McpException::invalidInput('Give either durationMinutes or endedAt, not both.');
        }
        if (null !== $explicitEnd) {
            return $explicitEnd;
        }
        if (null === $minutes) {
            throw McpException::invalidInput('Give either durationMinutes or endedAt. To start a running timer instead, use start_timer.');
        }
        if ($minutes < 1) {
            throw McpException::invalidInput('durationMinutes must be at least 1.');
        }

        return $startedAt->modify(sprintf('+%d minutes', $minutes));
    }
}
