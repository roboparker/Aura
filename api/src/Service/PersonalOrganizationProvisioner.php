<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Provisions a user's personal {@see Organization} — their own account, in the
 * GitHub sense: it owns their personal space and can hold a plan.
 *
 * Phase 2 of the account model exists to delete the "a space might have no
 * account behind it" branch from `SpaceCreateProcessor`, `SpaceMemberAdder`,
 * the access extensions, and the three-way fallback in
 * `SubscriptionRepository::findActiveForSpace()`. That is only possible if
 * *every* user has an organization, which is what this guarantees.
 *
 * Deliberately **does not flush**: the caller provisions the org, the space and
 * the membership in one transaction, so a half-failed signup can't leave a user
 * with an account but no space, or a space with no account.
 */
final class PersonalOrganizationProvisioner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrganizationRepository $organizations,
    ) {
    }

    /**
     * The user's personal organization, creating it if absent. Idempotent, so
     * the SSO and password signup paths can both call it without coordinating,
     * and so a backfilled account isn't given a second one.
     */
    public function provision(User $user): Organization
    {
        $this->refuseAgents($user);

        $existing = $this->organizations->findPersonalFor($user);
        if (null !== $existing) {
            return $existing;
        }

        $organization = $this->build($user);
        $this->em->persist($organization);

        return $organization;
    }

    /**
     * Construct a personal organization **without persisting it**, so callers
     * that already own the flush can wire it in themselves — notably
     * {@see \App\EventListener\SpaceOrganizationDefaultListener}, which runs
     * inside `onFlush` where an ordinary persist() would be too late to be
     * picked up.
     */
    public function build(User $user): Organization
    {
        $this->refuseAgents($user);

        $organization = (new Organization())
            ->setName($this->nameFor($user))
            ->setSlug($this->uniqueSlugFor($user))
            ->setIsPersonal(true)
            ->setCreatedBy($user);
        // Owner, so every invariant that asks "does this org have an owner?"
        // holds for personal accounts too without a special case.
        $organization->addMember($user, Organization::ROLE_OWNER);

        return $organization;
    }

    /**
     * An AI agent (#827) never gets a personal organization.
     *
     * A personal org is *an account*: it can hold a plan, own spaces and be
     * billed. An agent is a credential belonging to the org that created it,
     * so giving it one would invent an account nobody owns — and one holding
     * an Owner membership, which several invariants read as "a real person is
     * responsible for this".
     *
     * This throws rather than returning null because there is no caller for
     * which "an agent with no organization" is a recoverable outcome: every
     * one of them is about to attach a space or a subscription to the result.
     * Reaching here means a code path treated an agent as a person, which is
     * a bug to see, not to absorb.
     */
    private function refuseAgents(User $user): void
    {
        if ($user->isAgent()) {
            throw new \LogicException('AI agents do not have a personal organization.');
        }
    }

    /**
     * Their display name, falling back to the local part of their email. Shown
     * in the org switcher, so it should read as *them* rather than a slug.
     */
    private function nameFor(User $user): string
    {
        $name = trim($user->getGivenName() . ' ' . $user->getFamilyName());
        if ('' !== $name) {
            return $name;
        }
        $email = $user->getEmail();
        $at = strpos($email, '@');

        return false === $at ? $email : substr($email, 0, $at);
    }

    /**
     * A stable `o-` slug derived from the email local part, with a numeric
     * suffix on collision. Two people called `alex@` at different domains would
     * otherwise fight over the same handle.
     */
    private function uniqueSlugFor(User $user): string
    {
        $email = $user->getEmail();
        $at = strpos($email, '@');
        $local = false === $at ? $email : substr($email, 0, $at);

        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($local)), '-');
        if ('' === $base) {
            $base = 'user';
        }
        $base = substr($base, 0, 60);

        $slug = 'o-' . $base;
        $suffix = 1;
        while (null !== $this->organizations->findOneBy(['slug' => $slug])) {
            ++$suffix;
            $slug = 'o-' . $base . '-' . $suffix;
        }

        return $slug;
    }
}
