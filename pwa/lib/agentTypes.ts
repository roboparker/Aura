/**
 * AI agents (#827). Mirrors the payload shape of
 * `App\Controller\SpaceAgentController`.
 *
 * An agent is a user account flagged `isAgent` — the server models it that way
 * so every permission check keeps working — but it is never a person, so the
 * client's job is to keep the two visually and structurally separate: agents
 * are listed in their own section rather than mixed into the member roster,
 * and {@link isAgentMember} is what human-facing lists filter on.
 */
import { type SpaceRoleRef } from "@/lib/roleTypes";

export interface SpaceAgent {
  id: string;
  "@id": string;
  name: string;
  /** The agent's `SpaceMembership` id — what role edits and removal address. */
  membershipId: string;
  personalizedColor: string;
  roles: SpaceRoleRef[];
  createdAt: string;
  /**
   * The plaintext bearer, present **only** in the create response. There is no
   * way to read it again — only its hash is stored — so it has to be shown
   * once and copied there and then.
   */
  plainToken?: string;
}

export interface SpaceAgentCollection {
  agents: SpaceAgent[];
}

/**
 * Whether a user-like object is an AI agent.
 *
 * Defaults to `false` when the field is absent so a payload from a surface
 * that doesn't serialize it is treated as a person — the safe direction for a
 * roster, where wrongly hiding a colleague is worse than showing an agent.
 */
export const isAgentMember = (user: { isAgent?: boolean }): boolean =>
  user.isAgent === true;

/**
 * An account's AI allowance for the current month (#827), from
 * `GET /spaces/{id}/ai-credits`.
 *
 * Credits pool at the **organization**, not the space — two spaces in the same
 * account report the same numbers, which is why the payload names the account.
 * The server meters in tokens and converts here at the boundary; the UI only
 * ever shows credits.
 */
export interface AiCreditBalance {
  period: string;
  unlimited: boolean;
  usedCredits: number;
  allowanceCredits: number | null;
  usedTokens: number;
  allowanceTokens: number | null;
  remainingTokens: number | null;
  tokensPerCredit: number;
  organization: { id: string; name: string };
  /**
   * Null when agents can answer. Otherwise a stable key — the call to action
   * for `plan_not_entitled` (upgrade) is nothing like the one for
   * `provider_not_configured` (talk to whoever runs this instance).
   */
  unavailableReason:
    | "plan_not_entitled"
    | "credits_exhausted"
    | "provider_not_configured"
    | "no_account"
    | null;
  unavailableMessage: string | null;
}

/** Percentage of the monthly allowance consumed, clamped for the meter bar. */
export const creditUsagePercent = (balance: AiCreditBalance): number => {
  if (balance.unlimited || !balance.allowanceCredits) return 0;
  return Math.min(
    100,
    Math.round((balance.usedCredits / balance.allowanceCredits) * 100),
  );
};
