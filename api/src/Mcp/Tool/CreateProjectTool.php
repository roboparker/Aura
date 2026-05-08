<?php

namespace App\Mcp\Tool;

use App\Entity\Project;
use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

final class CreateProjectTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
    ) {
    }

    public function getName(): string
    {
        return 'create_project';
    }

    public function getDescription(): string
    {
        return 'Create a new shared project. The authenticated user becomes the owner and the only initial member; add more members via the project settings UI or future tools.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Project title (required).'],
                'description' => ['type' => 'string', 'description' => 'Optional Markdown description.'],
            ],
            'required' => ['title'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $title = $this->input->requireString($arguments, 'title');
        $description = $this->input->optionalString($arguments, 'description');

        $project = new Project();
        $project->setOwner($user);
        // Space defaults to the caller's personal space via
        // ProjectSpaceDefaultListener at PrePersist (#185), where they
        // already hold the admin role. No project-local member set
        // exists anymore.
        $project->setTitle($title);
        if (null !== $description) {
            $project->setDescription($description);
        }

        $this->input->assertValid($project);
        $this->em->persist($project);
        $this->em->flush();

        return $this->serializer->project($project, taskCount: 0, openTaskCount: 0);
    }
}
