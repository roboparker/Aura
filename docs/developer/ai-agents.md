# AI agents

An **AI agent** is a member of a space that isn't a person: it holds
permissions, holds a credential, and (from a later step) can be talked to.

This document covers **steps 1–3** of
[#827](https://github.com/roboparker/Aura/issues/827) — an agent that exists,
has permissions and holds a token (step 1), the model provider seam plus the
credit ledger that meters it (step 2), and conversation storage with the chat
dock (step 3). The left-nav Agents section is step 4.

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

---

# Step 2 — the model provider and the credit meter

## The provider seam

`App\Ai\ChatProviderInterface` is the same shape as `StripeGatewayInterface` and
`CalendarClientInterface`: a thin domain-typed interface, HTTP via Symfony
HttpClient rather than a vendor SDK, an in-memory double for tests, and a
`ChatProviderRegistry` keyed by name so a second model drops in as one class.

The types that cross it are `ChatMessage`, `ChatRequest` and `ChatResponse`, and
they are deliberately the smallest thing that works — a role and some text in,
text plus token counts out. Providers have far richer message shapes (content
parts, tool calls, images), and **every one of those we adopt becomes something
the second provider has to be translated into**. Anything richer belongs inside
an implementation, built from these fields. The one unavoidable leak is the
model *identifier*, a bare string; call sites take
`ChatProviderInterface::defaultModel()` rather than hard-coding one, so changing
models is configuration.

Failures collapse onto one `ChatProviderException` because a caller's response
is identical whichever it is. The single distinction preserved is `retryable`,
since that is the only thing that changes what a caller may do next.

`OpenAiChatProvider` reads `OPENAI_API_KEY` (blank = not configured, like the
Stripe and VAPID keys) with an optional `OPENAI_MODEL` override; the default is
a small cheap model, because the default is what an unattended loop multiplies.

## The credit meter

`ai_credits_per_month` has existed in `PlanCatalog` since Phase 0 — Free/Pro 0,
Business 2000, Enterprise 10000 — and until now **nothing read it**: the pricing
page sold credits that were never granted or spent. `App\Service\AiCreditMeter`
is the thing that reads it.

### Reserve, then reconcile

Per the issue: charge on tokens consumed, not messages, and reserve before the
call so a mid-flight failure cannot overspend.

1. `reserve()` writes a `pending` ledger row for the **most** the call could cost
   (prompt estimate + `maxOutputTokens`) and refuses outright if it won't fit.
   From that instant it counts against the balance.
2. `settle()` rewrites the row to the provider's reported usage — nearly always
   less, releasing the difference.
3. `release()` deletes the row when the call failed. A failed call is not a
   charge.

Charging *afterwards* would make a crash between the call and the write free,
and "free when it breaks" is what turns an agent loop into an unbounded bill.

### Why a ledger, and why it locks

A single running total cannot express an in-flight amount that might yet be
released, so each charge is its own row with a `pending → settled` lifecycle.

Writes go through DBAL rather than the ORM, like `UsageRecorder` over
`UserUsageCounter`. Here it is load-bearing rather than stylistic: reserving is
*check the balance and insert, atomically*, and without that atomicity two
concurrent requests both see the last of the allowance and both spend it.
`reserve()` takes a `FOR UPDATE` row lock on the organization for the duration,
which serialises reservations per account. At chat volumes that costs nothing,
and it is the difference between a cap and a suggestion.

### Self-healing reservations

If a process dies between reserving and settling, the pending row would hold
credits hostage forever. Rather than depend on a cron to notice, the balance
query ignores pending rows past `expiresAt` (15 minutes — well above the 60s
provider timeout, well below anything a person would wait). The sweep that
deletes them rides along on the nightly usage-snapshot job and is pure
housekeeping: it can never be the difference between a customer being able to
use the product or not.

### Tokens, not credits

The ledger stores **tokens**, the unit providers bill in. Credits are a
presentation layer over them (`app.ai_tokens_per_credit`, default 1000), applied
only at the plan boundary and in the UI, so rounding never accumulates across a
month of charges. Displayed usage rounds **up** — a partial credit spent is a
credit spent, and rounding down would let a long tail of small calls read as
zero usage against a plan being quietly consumed.

### Not behind the billing dark-launch flag

Every other cap in `UsageLimiter` short-circuits to "allowed" while
`app.billing_enforcement_enabled` is false, so the freemium gate could ship
before Stripe was live. **Credit enforcement is always on.**

The other caps protect revenue: too permissive and we undercharge, which is
recoverable. This one protects spend against a third party's meter: too
permissive and we are the ones being billed, without limit, by something a
language model drives. Those two failures do not deserve the same default.

A consequence worth stating: on an instance where nobody is on Business or
Enterprise, no agent can call a model. That is the correct posture — and no
provider key is configured on such an instance either.

## The metered entry point

`App\Service\AgentChatService::reply()` is the **only** way to make an agent say
something, so no future caller can reach a provider without paying for it. It
checks plan entitlement, resolves a provider, reserves, calls, and settles or
releases. Step 3's chat storage calls this and nothing else.

`unavailableReason()` runs the same checks without the reservation, so a UI can
disable a composer with a reason instead of letting someone type a message that
was never going to be answered.

## Reading the balance

`GET /spaces/{id}/ai-credits` (`App\Controller\AiCreditController`), readable by
**any space member** — someone about to talk to an agent needs to know whether
it can answer, and the numbers are aggregate usage rather than anything
sensitive. Addressed by space even though credits pool at the organization,
because a space is what the PWA has in hand everywhere agents are managed; the
payload names the account so the pooling is visible rather than surprising when
two spaces show the same numbers.

It also returns `unavailableReason` as a stable key. That matters because
`plan_not_entitled` is a sales moment that should link to the upgrade, while
`provider_not_configured` is an operator problem the viewer can do nothing
about — showing an upgrade button for the latter would charge someone for a fix
that isn't theirs to make.

PWA: `AiCreditsMeter` sits above the agent list on the space Users page, which
is where "how much is left" is the question you actually have.

## Configuration

| Key | Default | Meaning |
|---|---|---|
| `OPENAI_API_KEY` | blank | Blank = no model configured; agents report themselves unavailable |
| `OPENAI_MODEL` | blank | Overrides the provider's own default |
| `app.ai_tokens_per_credit` | 1000 | Exchange rate between the ledger's tokens and the plan's credits |

## Tests

- `App\Tests\Api\AgentTest` — provisioning, the one-shot plaintext, the
  permission gate, role rewriting across both grants, removal, and each thing
  the `isAgent` flag suppresses.
- `App\Tests\Api\AiCreditTest` — the reserve/settle ordering (including
  observing the balance *while the provider is mid-call*), release on failure,
  plan enforcement, allowance arithmetic, self-healing reservations, and the
  read endpoint.
- `App\Tests\Ai\OpenAiChatProviderTest` — the OpenAI translation layer, which
  the in-memory double by definition cannot cover.
- `pwa/lib/agentTypes.test.ts` — the roster filter and the usage meter's
  clamping and zero-allowance guard.

---

# Step 3 — conversations and the chat dock

## One thread per (person, agent)

`AgentConversation` is unique on `(agent, user)`. Not per space, and never
shared: an agent belongs to a space, but a conversation is *someone talking to
it*, and two colleagues talking to the same agent are having two different
conversations. A shared thread would also make everything one person said into
context the model sees for everybody else — a privacy surprise, and a way to
plant instructions in a colleague's session.

Threads are flat and chronological, like the `Comment` threads. No branching in
v1.

`AgentMessage` stores only what a turn *is* — role and body, plus a `truncated`
flag. Token counts stay in the credit ledger, which is the record of what was
spent; a second copy here could disagree with the bill. The **system prompt is
not stored**: it is rebuilt from the agent and space on every send, so changing
it takes effect on existing threads immediately rather than leaving old
conversations running under an instruction nobody can see.

## A turn is one transaction

`AgentConversationService::send()` wraps the person's message, the model call
and the answer in a single transaction. If the model fails, the question is
rolled back with it.

The alternative — keeping the unanswered question — leaves the thread in a state
whose only escape is a retry that then posts the message twice. Rolling back
means the client simply keeps the draft, which is the one place a retry is
unambiguous. `AgentChatDock` therefore clears its composer only on success.

## What the model is shown

Only the last `HISTORY_TURNS` (20) turns are replayed, plus the system prompt.

**This is a cost control, not a UI limit.** The whole window is re-sent on every
message, so an unbounded history means each message in a long conversation costs
more than the last, without anyone choosing that. The stored thread is never
trimmed — only what the model sees.

The system prompt has two jobs. It says **what the agent can do**: a v1 agent is
chat-only, and one that cheerfully agrees to move a card it cannot touch is
worse than one that declines. And it says **conversation content is data**:
everything after it was written by people, and a message trying to rewrite the
agent's rules is exactly the prompt-injection shape to expect. That line is the
cheap first defence; the real backstop is still the narrow token scope from
step 1.

## Endpoints

`App\Controller\AgentChatController` — deliberately not an API Platform
resource, because sending a message is a *command with a cost* (it reserves
credits, calls a third party, and fails in ways that must be told apart) rather
than a collection POST.

| Route | Notes |
|---|---|
| `GET /agents/{id}/chat` | The caller's thread, created on first open, plus availability |
| `POST /agents/{id}/chat/messages` | `{body}` → both turns |
| `DELETE /agents/{id}/chat` | Forget the thread; idempotent |

**Any member of the agent's space may chat with it.** An agent is a space
resource, like a board: provisioning one is admin-gated because it mints a
credential, but using one is not. Privacy still holds, because the conversation
is keyed on the caller — no route here takes an id that could address someone
else's thread.

Everything unreachable is a flat **404**, including a perfectly real human user
id. These routes must not become a way to probe which accounts exist or which
of them are agents.

Failure statuses are split on purpose: **402** when the *account* can't spend
(plan or credits — an upgrade helps) and **503** when the *instance* has no
model configured (an upgrade would not help at all). Collapsing them would send
half of these users to a pricing page that cannot fix their problem.

## The dock

`AgentChatDock` is mounted once in `Layout`, outside `AppShell`, and renders
nothing until an agent is opened — so it costs no DOM for sessions that never
use one. It is **not a route**: it stays open across navigation, which is the
point of a dock, and it doesn't take over the page someone is working on.
`AgentChatContext` holds only the open agent's identity, so any surface that
lists agents can open it without knowing where it's mounted.

Until the left-nav Agents section lands (step 4), the way in is a **Chat** button
on each row of the space Users page.

v1 is send-and-wait: a spinner while the model answers, then the reply. The
issue calls streaming a nice-to-have that shouldn't block v1, and it isn't free
— it needs a second transport (SSE) and a partial-message state in storage, both
of which want designing rather than bolting on.

## Tests

`App\Tests\Api\AgentChatTest` covers the round trip, what the model is actually
shown, thread privacy between colleagues, the flat-404 surface, rollback on a
failed call, and the history window.
