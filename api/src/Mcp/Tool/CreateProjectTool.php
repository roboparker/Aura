<?php

namespace App\Mcp\Tool;

use App\Entity\Project;
use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpInputHelper;
use App\Mcp\McpSpaceResolver;
use Doctrine\ORM\EntityManagerInterface;

final class CreateProjectTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpEntitySerializer $serializer,
        private McpInputHelper $input,
        private McpSpaceResolver $spaces,
    ) {
    }

    public function getName(): string
    {
        return 'create_project';
    }

    public function getDescription(): string
    {
        return 'Create a new project. The authenticated user becomes the owner. Pass a spaceId (from list_spaces) to create it in a shared space; omit it to use your personal space. Members come from the space, not the project.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Project title (required).'],
                'description' => ['type' => 'string', 'description' => 'Optional Markdown description.'],
                'spaceId' => ['type' => 'string', 'description' => 'UUID of a space the user belongs to. Omit for the personal space.'],
            ],
            'required' => ['title'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $title = $this->input->requireString($arguments, 'title');
        $description = $this->input->optionalString($arguments, 'description');
        // An explicit space must be one the caller belongs to; omitted
        // leaves space null so ProjectSpaceDefaultListener defaults it to
        // the caller's personal space (where they're admin) at persist.
        $space = $this->spaces->resolveMemberSpaceOrNull($arguments['spaceId'] ?? null, $user);

        $project = new Project();
        $project->setOwner($user);
        if (null !== $space) {
            $project->setSpace($space);
        }
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
