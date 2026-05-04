<?php

namespace App\Mcp\Tool;

use App\Entity\Project;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

final class DeleteProjectTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpAuthorization $authz,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'delete_project';
    }

    public function getDescription(): string
    {
        return 'Permanently delete a project and detach its tasks (tasks survive — `project` becomes null on each, mirroring the PWA delete).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'projectId' => ['type' => 'string'],
            ],
            'required' => ['projectId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $projectId = $this->input->requireUuid('projectId', $arguments['projectId'] ?? null);
        $project = $this->em->getRepository(Project::class)->find($projectId);
        if (null === $project || !$this->authz->canEditProject($project, $user)) {
            throw McpException::notFound(sprintf('Project %s', $projectId));
        }
        $this->em->remove($project);
        $this->em->flush();
        return ['deleted' => true, 'projectId' => (string) $projectId];
    }
}
