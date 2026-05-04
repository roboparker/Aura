<?php

namespace App\Mcp\Tool;

use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

final class ListFilesTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpAuthorization $authz,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'list_files';
    }

    public function getDescription(): string
    {
        return 'List file attachments on a task or project. Returns metadata (id, name, MIME type, size, gated download URL) — bytes are fetched separately via download_file.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'entityType' => ['type' => 'string', 'enum' => ['task', 'project']],
                'entityId' => ['type' => 'string'],
            ],
            'required' => ['entityType', 'entityId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $entityType = $this->input->requireString($arguments, 'entityType');
        if (!in_array($entityType, ['task', 'project'], true)) {
            throw McpException::invalidInput('entityType must be "task" or "project".');
        }
        $entityId = $this->input->requireUuid('entityId', $arguments['entityId'] ?? null);

        if ('task' === $entityType) {
            $task = $this->em->getRepository(Task::class)->find($entityId);
            if (null === $task || !$this->authz->canReadTask($task, $user)) {
                throw McpException::notFound(sprintf('Task %s', $entityId));
            }
            $attachments = $task->getAttachments();
        } else {
            $project = $this->em->getRepository(Project::class)->find($entityId);
            if (null === $project || !$this->authz->canReadProject($project, $user)) {
                throw McpException::notFound(sprintf('Project %s', $entityId));
            }
            $attachments = $project->getAttachments();
        }

        return [
            'items' => array_map(
                fn ($m) => $this->serializer->mediaObject($m),
                $attachments->toArray(),
            ),
        ];
    }
}
