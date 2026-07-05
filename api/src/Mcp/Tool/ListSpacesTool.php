<?php

namespace App\Mcp\Tool;

use App\Entity\Space;
use App\Entity\UserGroup;
use App\Entity\SpaceMembership;
use App\Entity\User;
use App\Mcp\McpEntitySerializer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lists the spaces the caller belongs to — the containers that boards,
 * pages, and discussions live in. Most other create tools accept a
 * `spaceId`, so this is the discovery step that lets the model target a
 * shared space instead of always defaulting to the personal one.
 */
final class ListSpacesTool implements McpToolInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private McpEntitySerializer $serializer,
    ) {
    }

    public function getName(): string
    {
        return 'list_spaces';
    }

    public function getDescription(): string
    {
        return 'List the spaces the user belongs to (personal first, then shared), with the user\'s role in each. Spaces are the top-level containers for boards, pages, and discussions; pass a returned space id as spaceId to other create tools to place content in a shared space.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    public function invoke(array $arguments, User $user): array
    {
        // Direct or group-inherited membership, mirroring
        // SpaceAccessExtension's EXISTS scoping.
        $direct = sprintf(
            'SELECT 1 FROM %s sp_direct WHERE sp_direct.space = s AND sp_direct.user = :user',
            SpaceMembership::class,
        );
        $group = sprintf(
            'SELECT 1 FROM %s sp_grp_obj '
            . 'JOIN sp_grp_obj.memberships sp_grp_member '
            . 'WHERE sp_grp_obj.space = s AND sp_grp_member.user = :user',
            UserGroup::class,
        );

        $spaces = $this->em->getRepository(Space::class)
            ->createQueryBuilder('s')
            ->where(sprintf('(EXISTS(%s) OR EXISTS(%s))', $direct, $group))
            ->setParameter('user', $user)
            ->orderBy('s.isPersonal', 'DESC')
            ->addOrderBy('s.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        return [
            'items' => array_map(
                fn (Space $space) => $this->serializer->space($space, $user),
                $spaces,
            ),
        ];
    }
}
