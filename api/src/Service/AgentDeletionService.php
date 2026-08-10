<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes an AI agent (#827) — its access, its credentials and its row —
 * without destroying anything it wrote.
 *
 * ## Why this is not `AccountDeletionService`
 *
 * An agent is a credential, not a person: there is no account holder with a
 * claim to a grace period, nothing to email a restore link to, no personal
 * organization, no subscription to cancel at Stripe, and no space to promote a
 * successor in (an agent is never a space admin, by construction). Running the
 * account path would be a lot of machinery answering questions an agent
 * doesn't raise.
 *
 * What it *does* share is the part that matters: authored content is reassigned
 * through {@see AuthorshipSentinel} rather than cascaded away, using the same
 * list of authorship FKs, so the two paths cannot drift.
 *
 * ## Why this exists now rather than when agents can write
 *
 * Today a v1 agent authors nothing — it is chat-only and holds no ROLE_USER, so
 * it cannot reach a single write endpoint. A plain `remove()` is therefore
 * correct *right now* and would stay correct until the day autonomy ships, at
 * which point it would silently start deleting other people's task comments
 * along with the agent that wrote them. Nothing would fail; content would just
 * be gone. That is the kind of bug worth paying for in advance.
 */
final class AgentDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuthorshipSentinel $authorship,
    ) {
    }

    /**
     * Delete an agent, preserving whatever it authored.
     *
     * @throws \InvalidArgumentException if handed a person, or a placeholder
     */
    public function delete(User $agent): void
    {
        if (!$agent->isAgent()) {
            // The agent endpoints already refuse to act on a human member;
            // this is the backstop, because a deletion path that quietly
            // accepted a person would skip every invariant
            // SpaceMemberController enforces.
            throw new \InvalidArgumentException('Only an AI agent can be deleted this way.');
        }
        if ($this->authorship->isSentinel($agent)) {
            throw new \InvalidArgumentException('The system sentinel account cannot be deleted.');
        }

        $this->em->wrapInTransaction(function () use ($agent): void {
            $agentId = (string) $agent->getId();

            // Anything it wrote survives under the "Removed agent" placeholder
            // — still visibly machine-written, which is the whole reason agents
            // don't inherit the human "Former member" account.
            $this->authorship->reassign($agent, $this->authorship->sentinelFor($agent));

            // Revocation stated rather than inherited. `api_token.user_id` is
            // ON DELETE CASCADE, so this is belt and braces — but a credential
            // going away is the security-relevant half of removing an agent,
            // and it should not depend on a schema detail somebody could
            // reasonably change.
            foreach ($this->em->getRepository(ApiToken::class)->findBy(['user' => $agent]) as $token) {
                $this->em->remove($token);
            }

            $managed = $this->em->find(User::class, $agentId);
            if (null !== $managed) {
                // The rest goes by CASCADE, and each one is intended:
                //  - space_membership → the agent leaves the space.
                //  - agent_conversation (+ its messages) → a thread with an
                //    agent that no longer exists is unreachable and meaningless
                //    to the person who held it.
                //  - ai_credit_ledger.agent_id is SET NULL, not CASCADE, so the
                //    record of what it spent outlives it. Deleting an agent
                //    must not be a way to make a month's bill disappear.
                $this->em->remove($managed);
            }
        });
    }
}
