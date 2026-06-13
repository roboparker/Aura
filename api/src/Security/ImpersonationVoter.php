<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Gates admin impersonation (the firewall's switch_user feature) on the
 * target user's explicit consent.
 *
 * Symfony's SwitchUserListener authorizes a switch by asking the access
 * decision manager to decide `ROLE_ALLOWED_TO_SWITCH` with the target user
 * as the subject. We deliberately do NOT grant ROLE_ALLOWED_TO_SWITCH
 * through role_hierarchy (it used to be `ROLE_ADMIN: [ROLE_ALLOWED_TO_SWITCH]`)
 * so this voter is the sole authority on the attribute: the built-in
 * RoleVoter abstains on it and, under the default affirmative strategy, our
 * decision stands.
 *
 * Access is granted only when BOTH hold:
 *  - the operator is a platform admin (ROLE_ADMIN), and
 *  - the target user has opted in via the `canBeImpersonated` preference
 *    (off by default).
 *
 * Switch-OUT (?_switch_user=_exit) is unaffected — the listener unwraps the
 * SwitchUserToken without consulting this attribute — so an admin can always
 * leave an impersonation session even if the target later revokes consent.
 */
final class ImpersonationVoter extends Voter
{
    public function __construct(private Security $security)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return 'ROLE_ALLOWED_TO_SWITCH' === $attribute && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        // Only platform admins may impersonate at all. isGranted('ROLE_ADMIN')
        // recurses into the decision manager, but this voter abstains on
        // ROLE_ADMIN (supports() requires ROLE_ALLOWED_TO_SWITCH), so there's
        // no loop.
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return false;
        }

        // ...and only when the target account has opted in.
        return $subject instanceof User && $subject->canBeImpersonated();
    }
}
