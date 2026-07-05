<?php

namespace App\Mcp\Tool;

use App\Entity\CustomFieldDefinition;
use App\Entity\Board;
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
        return 'List custom field definitions for a board, ordered by position. Returns each field\'s name, kind/subtype (boolean.boolean, text.{text,rich_text,url}, numeric.{int,float,money}, date.{date,time,datetime}, select.{single,multi}, reference.{user,task,page,discussion}), config, optional footer aggregation descriptor, nullable flag, and visibility (list|board|both).';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'boardId' => ['type' => 'string'],
            ],
            'required' => ['boardId'],
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        $boardId = $this->input->requireUuid('boardId', $arguments['boardId'] ?? null);
        $board = $this->em->getRepository(Board::class)->find($boardId);
        if (null === $board || !$this->authz->canReadProject($board, $user)) {
            throw McpException::notFound(sprintf('Board %s', $boardId));
        }

        // Fields are space-owned now (#custom-fields-space) but per-board
        // shown — list the fields this board has opted into.
        $fields = $this->em->getRepository(CustomFieldDefinition::class)
            ->createQueryBuilder('f')
            ->innerJoin('f.boards', 'p')
            ->where('p = :board')
            ->setParameter('board', $board)
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
