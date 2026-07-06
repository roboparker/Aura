<?php

namespace App\Mcp;

use App\Entity\Space;
use App\Entity\User;
use App\Repository\SpaceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Resolves the {@see Space} a tool call should write into.
 *
 * Spaces gate every higher-level content type (pages, discussions,
 * boards), but the existing PWA — and most quick "just jot this down"
 * tool calls — don't supply one. So the rule mirrors the REST default:
 * an omitted space falls back to the caller's personal space (where
 * they're always an admin), and an explicit space id must be one the
 * caller belongs to (404 otherwise, matching the access-extension
 * existence-hiding shape used everywhere else).
 */
final class McpSpaceResolver
{
    public function __construct(
        private EntityManagerInterface $em,
        private SpaceRepository $spaces,
        private McpInputHelper $input,
    ) {
    }

    /**
     * The caller's personal "Private" space. Every account provisioned
     * through signup has one; the 404 only fires for synthetic users
     * created outside the normal flow.
     */
    public function personalSpace(User $user): Space
    {
        $space = $this->spaces->findPersonalSpaceFor($user);
        if (!$space instanceof Space) {
            throw new McpException('No personal space found for this account.', 404);
        }
        return $space;
    }

    /**
     * Resolve an explicit space id the caller must be a member of, or
     * null when none was supplied (so callers can decide their own
     * default). Returns 404 for a space the caller can't see.
     */
    public function resolveMemberSpaceOrNull(mixed $rawSpaceId, User $user): ?Space
    {
        if (null === $rawSpaceId) {
            return null;
        }
        $id = $this->input->requireUuid('spaceId', $rawSpaceId);
        $space = $this->em->getRepository(Space::class)->find($id);
        if (!$space instanceof Space || !$space->hasMember($user)) {
            throw McpException::notFound(sprintf('Space %s', $id));
        }
        return $space;
    }

    /**
     * Resolve the space to write into, defaulting to the personal space
     * when the caller omits one.
     */
    public function resolveMemberSpace(mixed $rawSpaceId, User $user): Space
    {
        return $this->resolveMemberSpaceOrNull($rawSpaceId, $user) ?? $this->personalSpace($user);
    }
}
