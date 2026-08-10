# AI agents

An **AI agent** is a member of a space that isn't a person: it holds
permissions, holds a credential, and (from a later step) can be talked to.

This document covers **step 1** of [#827](https://github.com/roboparker/Aura/issues/827) —
an agent that exists, has permissions, and holds a token. There is no model
provider, no chat and no credit metering yet; those are steps 2–4.

## An agent is a `User` row

The single decision everything else follows from: an agent is a `User` with
`isAgent = true`, not a parallel entity.

Every authorization path in this codebase already takes a `User` —
`SpacePermissionResolver`, `SpacePermissionVoter`, every access extension,
every authorship FK, `@mention` resolution, task assignment. Modelling an agent
as its own entity would mean re-implementing all of them for a second subject
type, and the two implementations would drift the first time one of them
changed. Reusing `User` means the permission model has exactly one
implementation, which is the only durable guarantee against that drift.

It is also the shape the access layer already anticipated:
`App\Security\Access\ActorPolicyResolver` is documented as *"one enforcement
engine, three actors (impersonation / token / future agent)"*. This is the
third actor.

The cost of the decision is that `User` implies things an agent must not have.
The flag's whole job is to subtract them:

| A `User` implies | An agent gets | Where |
|---|---|---|
| Sign-in | Refused; no password is ever set | `User::getRoles()`, `UserChecker`, `AgentSignInDeniedException` |
| A personal organization | None | `PersonalOrganizationProvisioner` throws |
| A billable seat | Free | `Organization::seatCount()` skips agents |
| Email verification, digests, push | None | `NotificationDispatcher`, `NotificationDigestDispatcher` |
| A place in human pickers | Hidden by default | `Board::getMembers()`, `CommentMentionService` |

## Why an agent holds no `ROLE_USER`

`getRoles()` returns `['ROLE_AGENT']` and nothing else — the same fail-closed
idiom already used for waitlisted and unverified accounts, and for the same
reason: nearly every resource requires `ROLE_USER`, so withholding it means an
agent identity reaches nothing at all and each surface an agent should reach
has to be opened deliberately in a later step rather than inherited on day one.

For a credential a language model drives, that is the right default. The issue
names prompt injection as a live risk: an agent reads user-authored task and
page content, and content it reads is data, not instructions. Starting from
"reaches nothing" bounds the blast radius of a successful injection to what
somebody consciously turned on.

This does **not** make the configured permissions decorative.
`SpacePermissionResolver` never consults `getRoles()` — it reads the
`SpaceMembership` and its `SpaceRole`s — so the envelope an admin configures on
the Users page is live, and it is the ceiling the later autonomy steps narrow
against. `AgentTest::testAgentPermissionsAreStillLiveForTheResolver` pins that.

## What provisioning creates

`App\Service\AgentProvisioner::provision()` writes three ordinary rows. Nothing
here is new permission machinery:

1. **A `User`** flagged `isAgent`, with a synthetic address at
   `agents.invalid` (RFC 2606 reserved, so it can never resolve). `email` exists
   because it is the Symfony user identifier, not because anyone reads it. The
   admin-typed name goes in `nickname`, which wins in the PWA's `displayName()`.
2. **A `SpaceMembership`**, always with the space role `member` — never `admin`.
   A space admin bypasses every role check, so an agent holding it would be
   unbounded no matter which roles were assigned, and the whole containment
   story rests on those roles being the ceiling.
3. **A space-scoped `ApiToken`** carrying the same roles. `SpaceKeyAccessListener`
   gates a scoped key by role-CRUD *before* the entity security expressions, and
   the access extensions confine its rows to the one space.

The plaintext bearer is returned once and only its sha256 hash is stored,
exactly as for a human's token.

### The agent does not join the organization

Space membership normally implies an organization membership — Phase 1c's
auto-join in `SpaceMemberAdder`. Agent provisioning deliberately does not route
through it, for two reasons:

- An agent is free. Keeping it off the org roster means it cannot be counted
  from anywhere a seat is derived. `Organization::seatCount()` also skips agents
  as a second line of defence, because that number is pushed to Stripe as the
  subscription quantity and a wrong answer is a wrong invoice.
- The org guest cap would confine it to the built-in Guest role, silently
  discarding whatever roles the admin chose.

Access flows purely from space membership, which every access extension already
reads.

## Endpoints

All of `App\Controller\SpaceAgentController`, gated on the **`api_keys`**
permission rather than on space admin directly. That is the honest
classification: creating an agent mints a Bearer credential confined to this
space and narrowed to chosen roles, which is precisely what
`SpaceApiKeyController` does. The category is admin-reserved, so admins hold it
by default and a member holds it only through a role that grants it explicitly.
Non-members get a 404, matching the existence-hiding shape of the rest of the
space API.

| Route | Notes |
|---|---|
| `GET /spaces/{id}/agents` | The space's agents with their roles |
| `POST /spaces/{id}/agents` | `{name, roles: [iri]}` → the agent + `plainToken`, once |
| `PATCH /spaces/{id}/agents/{agentId}` | `{name?, roles?}` |
| `DELETE /spaces/{id}/agents/{agentId}` | Removes the row, membership and credentials |

Two behaviours worth knowing:

- **`PATCH` rewrites both grants.** The membership's roles and the token's roles
  are separate; leaving either behind is a hole. A narrowed agent whose token
  kept its old roles would keep acting on the old ceiling.
- **The routes refuse to act on a human member.** `findAgentMembership()`
  requires the target to be an agent, so these endpoints can never be turned on
  a colleague — that would skip every invariant `SpaceMemberController` enforces
  (last-admin, org roster, seats).

`DELETE` removes the `User` row outright rather than scheduling it like an
account deletion. That is right *for an agent*: it is a credential, not a
person, so there is no account holder with a claim to a grace period and
nothing to restore. Note for a later step: once agents author content, deletion
will need to route through the "Former member" sentinel reassignment in
`AccountDeletionService`, the same way a human's does.

## Agents in human-facing lists

The issue's guidance is to default agents *out* and opt in per surface, which is
what step 1 does. Currently opted out:

- `Board::getMembers()` — the serialized list that feeds member chips and the
  assignee picker. The filter is deliberately **not** in
  `getEffectiveMembers()`, which answers "who has access" and backs the
  attachment gate and mention parsing; narrowing that would be a permission
  change wearing a UI change's clothes.
- `CommentMentionService` — a v1 agent is chat-only and does not act on being
  `@`-mentioned. Resolving a mention to one would file a notification nothing
  reads and, worse, tell the author their message reached something that will
  answer it. This is the surface that opts back in when autonomy lands.
- The PWA member rosters, counts and the engagement staff picker, via
  `isAgentMember` in `pwa/lib/agentTypes.ts`.

Every serialized user chip carries `isAgent`, so a client can label the ones
that do surface — a comment author, say — rather than passing an agent off as a
colleague.

## PWA

Agents are managed on the admin-only space Users page
(`/spaces/{id}/users`) in their own card next to the human roster
(`pwa/components/spaces/SpaceAgents.tsx`): same place, because an agent is
granted access the same way a person is; separate card, because it is not one.
Roles are assigned with the same picker as a person's.

An agent with no roles is labelled **"No access"**, not "Member (default)". The
server's "a member with zero roles is unrestricted" rule is back-compat for
people who predate roles; an agent's credential is a space-scoped key, which
grants nothing without roles, so "default" would read as more access than it
has.

## Tests

`App\Tests\Api\AgentTest` covers provisioning, the one-shot plaintext, the
permission gate, role rewriting across both grants, removal, and each thing the
flag suppresses.
