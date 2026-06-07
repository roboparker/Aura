<?php

namespace App\Security;

use App\Entity\Segment;
use App\Entity\User;
use App\Service\SegmentEvaluator;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Resolves `is_granted('ROLE_SEGMENT_<KEY>')` against live segment
 * membership. Because segment membership is dynamic (rollout %, role rules,
 * manual overrides) the role can't live in the user's stored roles — this
 * voter answers for it on demand instead, so `is_granted` stays correct the
 * instant an admin changes a segment.
 *
 * Affirmative is the default access-decision strategy, so a GRANT here wins
 * over the built-in RoleVoter's DENY for a role that isn't in the token.
 *
 * @extends Voter<string, mixed>
 */
final class SegmentVoter extends Voter
{
    public function __construct(private SegmentEvaluator $evaluator)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return str_starts_with($attribute, Segment::ROLE_PREFIX);
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $key = Segment::roleToKey($attribute);
        if (null === $key) {
            return false;
        }

        return $this->evaluator->isUserInSegment($user, $key);
    }
}
