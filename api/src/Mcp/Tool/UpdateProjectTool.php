<?php

namespace App\Mcp\Tool;

use App\Entity\Project;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

final class UpdateProjectTool implements McpToolInterface
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
        return 'update_project';
    }

    public function getDescription(): string
    {
        return 'Update a project\'s title or description. Any project member may rename — same as the PWA.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'projectId' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
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

        if (array_key_exists('title', $arguments)) {
            $project->setTitle($this->input->requireString($arguments, 'title'));
        }
        if (array_key_exists('description', $arguments)) {
            $project->setDescription($this->input->optionalString($arguments, 'description'));
        }

        $this->input->assertValid($project);
        $this->em->flush();

        return $this->serializer->project($project);
    }
}
