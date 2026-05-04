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

final class GetProjectTool implements McpToolInterface
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
        return 'get_project';
    }

    public function getDescription(): string
    {
        return 'Fetch one project by id, including the member list and total/open task counts. Returns 404 when the user is not a member.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'projectId' => ['type' => 'string', 'description' => 'UUID of the project.'],
            ],
            'required' => ['projectId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $projectId = $this->input->requireUuid('projectId', $arguments['projectId'] ?? null);
        $project = $this->em->getRepository(Project::class)->find($projectId);
        if (null === $project || !$this->authz->canReadProject($project, $user)) {
            throw McpException::notFound(sprintf('Project %s', $projectId));
        }

        $taskRepo = $this->em->getRepository(Task::class);
        $taskCount = (int) $taskRepo->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.project = :project')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();
        $openTaskCount = (int) $taskRepo->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.project = :project AND t.completedOn IS NULL')
            ->setParameter('project', $project)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->serializer->project($project, taskCount: $taskCount, openTaskCount: $openTaskCount);
    }
}
