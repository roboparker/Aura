# Organizations, accounts & plans

Status: **in progress** — Phase 0 (plan entitlements) shipped; Phase 1
(Organizations) is landing in sub-phases. 1a–1c are in; 1d (PWA) is partial.

## The account model (GitHub-style)

An **account** is the thing that *owns spaces and carries a plan*. There are two
kinds, exactly like GitHub's personal vs organization accounts:

| Account | Owns | Plans |
|---|---|---|
| **Personal** (every `User`) | their personal spaces (`Space.organization = null`, owner = `Space.createdBy`) | Free → **Pro** (flat, individual) |
| **Organization** (created explicitly) | org spaces (`Space.organization` set) | Free → **Business** (per-seat) → **Enterprise** (per-seat + SSO/SCIM/audit) |

- A `Space` belongs to **either** a person **or** an organization — never both,
  never neither.
- A `User` always has their personal account **and** may belong to any number of
  organizations. Their spaces are therefore split across those accounts.
- Space-moves between accounts are **deferred** (a later phase).

`PlanGate` (Phase 0) is the seam: `planForSpace()` resolves an org space through
the org's subscription and a personal space through the owner-user's personal
subscription; `planForUser()` (for per-user limits like MCP throughput) is the
best plan across the user's personal account **and** all their orgs.

## Organization roles

Org roles govern *the account*; the existing `SpaceRole` (`admin`/`member`/
custom/`guest`) governs *content within a space* — two independent layers.

| Org role | Powers | Counts as a paid seat? |
|---|---|---|
| **Owner** | Everything incl. billing + delete the org; transferable; ≥1 required | ✅ |
| **Admin** | Members, spaces, roles, org settings — not billing/delete | ✅ |
| **Billing** | Subscription + invoices only | ✅ |
| **Member** | Normal seat; joins/creates spaces | ✅ |
| **Guest** | Restricted: only the `guest` space-role, specific spaces, no directory, can't create spaces | ❌ **free** |

- **Seat count** = distinct org members whose role ≠ Guest → the Stripe quantity.
- **Guest invariant (security-critical):** an org Guest can *only* ever be
  assigned the `guest` built-in `SpaceRole` in any of the org's spaces, so a
  Guest can never be elevated into a paying seat's capabilities. Enforced in
  three places, because each covers a direction the others miss:
  - `SpaceMemberAdder::attach()` caps the role at creation (both the
    add-by-email endpoint and invite acceptance route through it — a gap
    between those two paths would be a way in);
  - `SpaceMemberController::changeRole()` rejects promoting a guest;
  - `OrganizationGuestPolicy::applyGuestCap()` handles the *reverse* direction —
    demoting an existing member to Guest, which used to leave every space role
    they already held untouched. The account said guest, the spaces said admin,
    and the spaces won.

  `App\Tests\Api\OrganizationGuestInvariantTest` +
  `OrganizationSeatSyncTest::testDemotingToGuestCapsExistingSpaceAccess` are the
  safety net.

## Seat accounting (1c)

**Auto org-join.** Adding someone to an org-owned space they're not yet in the
org for auto-joins them, with the org role **mirrored from the space role**:

| Space role granted | Org role joined | Seat? |
|---|---|---|
| `admin` | `member` | ✅ billable |
| `member` | `guest` | ❌ free |

The reasoning: the space role is the only signal available at that moment.
Someone trusted to administer a space is a full participant; someone added to
collaborate on one space is an external guest until an org admin says otherwise.
The consequence worth knowing is that **the default path now produces a
read-mostly guest** — an org admin who wants a full seat passes an explicit
`orgRole` on `POST /spaces/{id}/members` (owner is not grantable there, and the
override itself is org-admin-gated: a space admin can add collaborators but
can't unilaterally raise the org's bill).

An **existing** org member's role is never touched by a space add — being added
to another space is not a reason to demote an admin to a guest.

**Seat → Stripe quantity sync.** Every membership change that crosses the seat
boundary queues `SyncOrganizationSeats`; `OrganizationSeatSync::sync()` pushes
`max(1, seatCount())` to the subscription's single item on the worker. Async on
purpose: sizing the seat count is our business, billing for it is Stripe's, and
a member add shouldn't fail because Stripe was briefly unreachable. Proration is
left to Stripe's default, and the gateway skips the write when the quantity
already matches, so a retry can't generate a spurious proration line.

## Organization deletion (1c)

Deleting an org cascades to every space it owns, which makes it the most
destructive action in the product: one owner's click would otherwise destroy
every board, task, page and comment every other member ever wrote. So deletion
is a **state**, not an event.

1. `POST /organizations/{id}/delete` — owner-only, step-up verified
   (`SensitiveActionVerifier`), name typed to confirm, cancellation reason
   required. Stamps `deletedAt` + `purgeAfter`, cancels billing **immediately**
   (nobody should pay through a grace period they asked to end), and queues a
   data export of every space.
2. Access stops at once. The org drops out of `GET /organizations`, and its
   spaces — plus everything in them — stop resolving, via a `NOT EXISTS` folded
   into `SpaceMembershipDql::userBelongsToBoardSpace()` so every consumer of
   that fragment inherits it in one place. The org's *item* route stays
   readable so its members can see the "scheduled for deletion" state.
3. `POST /organizations/{id}/restore` reverses it, any time before the purge.
   It deliberately does **not** resurrect the subscription — that money movement
   should be a decision someone makes again, not a side effect of undo.
4. `PurgeDeletedOrganizations` (nightly 04:20 UTC, or
   `bin/console app:organizations:purge [--dry-run]`) hard-deletes the ones
   whose window has lapsed. Subscription rows are detached rather than cascaded:
   what an account paid should outlive the account.

`GET /organizations/deleted` lists the caller's restorable orgs — without it a
deleted org is invisible in every listing and its owner has no route back to it.

Window: `app.organization_deletion_grace_days` (default 30, env
`ORGANIZATION_DELETION_GRACE_DAYS`). Each org stores its own `purge_after` at
deletion time rather than deriving it, so shortening the setting can never
retroactively bring forward a deletion someone was already promised.

> **Note on the exports:** they follow normal space-export retention
> (`app.space_export_retention_days`, default 7), which is *shorter* than the
> grace period. The download link is emailed at deletion time and is the
> delivery mechanism — it is not an archive that sits around until the purge.

## Entities (Phase 1a)

```
Organization
  id(uuid), name, slug(unique, 'o-' prefixed), createdBy(User), createdAt, updatedAt
OrganizationMembership          unique(organization, user)
  organization, user, role(Owner|Admin|Billing|Member|Guest), joinedAt
Space
  + organization  (nullable ManyToOne; null = personal, owner = createdBy)
Subscription  (Phase 1b)
  space  →  { organization | ownerUser }   (polymorphic account owner)
```

## Migration (prod-safe, backfill)

1. **1a:** create `organization` + `organization_membership`; add nullable
   `space.organization_id`. Backfill: each **non-personal** Space → a new
   Organization (owner = the space creator = `Owner` membership; every other
   space member → `Member`; name = the space name), then set
   `space.organization_id`. Personal spaces stay `null`. *No behavior change* —
   `PlanGate` still reads the space's own subscription.
2. **1b:** `Subscription` gains `organization_id` + `owner_user_id` (exactly one);
   backfill each existing (shared-space) subscription onto its space's new org;
   `PlanGate` resolution flips to the account. Personal Pro plans become
   purchasable via `/me/billing`.

## Sub-phases

- **1a** — entities + backfill migration + `/organizations` CRUD + membership
  endpoints (behavior-neutral). ← *this PR*
- **1b** — polymorphic `Subscription`, `PlanGate` via account, billing endpoints
  (`/organizations/{id}/billing`, `/me/billing`), plan constraints per account
  type. `/spaces/{id}/billing` kept as a shim.
- **1c** — guest role + seat invariant + auto org-join on space-add + seat →
  Stripe quantity sync + the deletion/restore/purge flow. ← *shipped*
- **1d** — PWA: org switcher, org settings/members/roles pages, personal-vs-org
  billing UI. *(Partial: the org index + detail pages exist; the danger-zone
  delete/restore UI and the seat-count surface are still to do.)*

## Deferred / open
- Space-moves between accounts.
- PWA danger zone for org deletion — the API is in, the UI isn't, so deletion
  is currently API-only.
- Org **invites** for unknown email addresses; `POST /organizations/{id}/members`
  still requires an existing user.
- AI usage-pack overflow (Phase 3), Enterprise SSO/SCIM/audit enforcement
  (Phase 4).
