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
        $existing = $this->organizations->findPersonalFor($user);
        if (null !== $existing) {
            return $existing;
        }

        $organization = (new Organization())
            ->setName($this->nameFor($user))
            ->setSlug($this->uniqueSlugFor($user))
            ->setIsPersonal(true)
            ->setCreatedBy($user);
        // Owner, so every invariant that asks "does this org have an owner?"
        // holds for personal accounts too without a special case.
        $organization->addMember($user, Organization::ROLE_OWNER);

        $this->em->persist($organization);

        return $organization;
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
