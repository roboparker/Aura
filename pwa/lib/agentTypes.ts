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
