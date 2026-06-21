<?php

namespace App\Mcp\Tool;

use App\Entity\CustomFieldDefinition;
use App\Entity\Project;
use App\Entity\User;
use App\Mcp\McpAuthorization;
use App\Mcp\McpEntitySerializer;
use App\Mcp\McpException;
use App\Mcp\McpInputHelper;
use Doctrine\ORM\EntityManagerInterface;

final class GetCustomFieldsTool implements McpToolInterface
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
        return 'get_custom_fields';
    }

    public function getDescription(): string
    {
        return 'List custom field definitions for a project, ordered by position. Returns each field\'s name, kind/subtype (boolean.boolean, text.{text,rich_text,url}, numeric.{int,float,money}, date.{date,time,datetime}, select.{single,multi}, reference.{user,task,page,discussion}), config, optional footer aggregation descriptor, nullable flag, and visibility (list|board|both).';
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
        if (null === $project || !$this->authz->canReadProject($project, $user)) {
            throw McpException::notFound(sprintf('Project %s', $projectId));
        }

        $fields = $this->em->getRepository(CustomFieldDefinition::class)
            ->createQueryBuilder('f')
            ->where('f.project = :project')
            ->setParameter('project', $project)
            ->orderBy('f.position', 'ASC')
            ->addOrderBy('f.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return [
            'items' => array_map(
                fn (CustomFieldDefinition $f) => $this->serializer->customFieldDefinition($f),
                $fields,
            ),
        ];
    }
}
