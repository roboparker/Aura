<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\Space;
use App\Entity\SpaceMembership;
use App\Entity\SpaceRole;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\State\ApiTokenCreateProcessor;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates an AI agent (#827): a {@see User} flagged `isAgent`, a
 * {@see SpaceMembership} in the space that owns it, and a space-scoped
 * {@see ApiToken} narrowed to the same roles.
 *
 * All three exist already and are used unchanged — that is the point of the
 * design. An agent gets its capabilities the way a person does (membership +
 * roles, read by `SpacePermissionResolver`) and authenticates the way a
 * programmatic caller does (a scoped Bearer token, confined to one space and
 * narrowed to its roles by `SpaceKeyAccessListener`). Nothing here is new
 * permission machinery; it is wiring three existing pieces to one identity.
 *
 * **The agent does not join the organization.** Space membership normally
 * implies an org membership (Phase 1c's auto-join in {@see SpaceMemberAdder}),
 * which is why this deliberately does not route through it. Two reasons: an
 * agent is free and must not appear anywhere a seat could be counted from, and
 * the org guest cap would confine it to the built-in Guest role, silently
 * discarding whatever roles the admin chose. Access flows purely from space
 * membership, which every access extension already reads.
 *
 * Does not flush — the caller owns the transaction, so an agent can never be
 * left half-built (a user with no membership, or a membership with no token).
 */
final class AgentProvisioner
{
    /** Family name given to every agent row. See {@see nameParts()}. */
    public const FAMILY_NAME = 'Agent';

    public const MAX_NAME_LENGTH = 80;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ApiTokenRepository $tokens,
        private readonly AvatarColorService $avatarColors,
    ) {
    }

    /**
     * Build the agent, its membership and its credential.
     *
     * The plaintext bearer is returned rather than stored — only its sha256
     * hash is persisted, exactly as for a human's token — so the caller can
     * show it once and never again.
     *
     * @param list<SpaceRole> $roles the space roles the agent may act under;
     *                               an empty list is allowed and yields an
     *                               agent that can do nothing yet
     *
     * @return array{agent: User, membership: SpaceMembership, token: ApiToken, plainToken: string}
     */
    public function provision(Space $space, string $name, array $roles): array
    {
        $agent = $this->buildAgent($name);
        $this->em->persist($agent);

        $membership = (new SpaceMembership())
            ->setUser($agent)
            // Never `admin`. A space admin bypasses every role check, so an
            // agent holding it would be unbounded no matter which roles were
            // assigned — and the whole containment story for a language-model
            // caller rests on those roles being the ceiling.
            ->setRole(Space::ROLE_MEMBER);
        foreach ($roles as $role) {
            $membership->addRole($role);
        }
        $space->addUserMembership($membership);
        $this->em->persist($membership);

        [$token, $plainToken] = $this->buildToken($space, $agent, $name, $roles);
        $this->em->persist($token);

        return [
            'agent' => $agent,
            'membership' => $membership,
            'token' => $token,
            'plainToken' => $plainToken,
        ];
    }

    /**
     * Every credential the agent holds. Used when it is removed, so revocation
     * is explicit rather than relying on the FK cascade to have covered it.
     *
     * @return list<ApiToken>
     */
    public function tokensFor(User $agent): array
    {
        return $this->tokens->findBy(['user' => $agent]);
    }

    private function buildAgent(string $name): User
    {
        [$givenName, $familyName] = $this->nameParts($name);

        $agent = new User();
        $agent
            ->setIsAgent(true)
            ->setEmail($this->uniqueEmail($name))
            ->setGivenName($givenName)
            ->setFamilyName($familyName)
            // `nickname` wins in the PWA's displayName(), so the agent reads as
            // the name the admin typed rather than the padded given/family
            // pair the NotBlank constraints force onto the row.
            ->setNickname($givenName)
            ->setPersonalizedColor($this->avatarColors->pick())
            // Nothing to verify — there is no inbox behind the address. Left
            // true so the account is never routed into the verification gate,
            // which it could not clear.
            ->setEmailVerified(true);

        // No password is ever set: the column stays at its empty-string
        // default, which no hasher can verify against, so the form login
        // cannot succeed even before UserChecker refuses agents outright.

        return $agent;
    }

    /**
     * A synthetic, unique, undeliverable identifier address.
     *
     * `email` is the Symfony user identifier and is uniquely indexed, so an
     * agent needs one whether or not anybody reads it. The random suffix — not
     * the slug alone — is what makes two agents called "Support" in different
     * spaces possible without the second create failing on a unique violation.
     */
    private function uniqueEmail(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        if ('' === $base) {
            $base = 'agent';
        }
        $base = substr($base, 0, 40);

        $repository = $this->em->getRepository(User::class);
        do {
            $email = sprintf('%s-%s@%s', $base, bin2hex(random_bytes(4)), User::AGENT_EMAIL_DOMAIN);
        } while (null !== $repository->findOneBy(['email' => $email]));

        return $email;
    }

    /**
     * `givenName` / `familyName` are NotBlank on User and there is no sensible
     * split of a one-field agent name, so the name goes in `givenName` (and
     * into `nickname`, which is what actually displays) and the family name is
     * a fixed literal. Both have to hold something for a later PATCH of the row
     * to validate, which is why a name that trims to nothing falls back rather
     * than being stored empty.
     *
     * @return array{0: string, 1: string}
     */
    private function nameParts(string $name): array
    {
        $given = mb_substr(trim($name), 0, 100);

        return ['' === $given ? self::FAMILY_NAME : $given, self::FAMILY_NAME];
    }

    /**
     * The agent's credential: a space-scoped key carrying the same roles as
     * its membership.
     *
     * Scoping matters twice over. `SpaceKeyAccessListener` gates the key by
     * role-CRUD *before* the entity security expressions, so the key can never
     * inherit anything from its user beyond what the roles grant; and the
     * access extensions confine its rows to this one space. Both hold
     * regardless of what the agent's membership later becomes.
     *
     * @param list<SpaceRole> $roles
     *
     * @return array{0: ApiToken, 1: string}
     */
    private function buildToken(Space $space, User $agent, string $name, array $roles): array
    {
        $plaintext = ApiToken::PLAINTEXT_PREFIX . ApiTokenCreateProcessor::generateSecret();

        $token = (new ApiToken())
            ->setUser($agent)
            ->setSpace($space)
            ->setName(mb_substr($name, 0, ApiToken::MAX_NAME_LENGTH))
            ->setTokenHash(hash('sha256', $plaintext));
        foreach ($roles as $role) {
            $token->addRole($role);
        }

        return [$token, $plaintext];
    }
}
