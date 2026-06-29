<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Space;
use App\Entity\SpaceRole;
use App\Security\Permission\SpacePermission;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seeds a space's built-in roles (#space-roles): "Member" (the editable default
 * for members with no assigned role — full access so a fresh space behaves as
 * before until an admin tightens it) and "Guest" (read content + comment).
 * Idempotent; does NOT flush (the caller owns the flush).
 */
final class SpaceRoleSeeder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function seed(Space $space): void
    {
        $repo = $this->em->getRepository(SpaceRole::class);

        if (null === $repo->findOneBy(['space' => $space, 'builtinKey' => SpaceRole::BUILTIN_MEMBER])) {
            $this->em->persist(
                (new SpaceRole())
                    ->setSpace($space)
                    ->setName('Member')
                    ->setBuiltinKey(SpaceRole::BUILTIN_MEMBER)
                    ->setColor('#64748b')
                    ->setPermissions(SpacePermission::fullMatrix()),
            );
        }

        if (null === $repo->findOneBy(['space' => $space, 'builtinKey' => SpaceRole::BUILTIN_GUEST])) {
            $this->em->persist(
                (new SpaceRole())
                    ->setSpace($space)
                    ->setName('Guest')
                    ->setBuiltinKey(SpaceRole::BUILTIN_GUEST)
                    ->setColor('#a1a1aa')
                    ->setPermissions(SpacePermission::guestMatrix()),
            );
        }
    }
}
