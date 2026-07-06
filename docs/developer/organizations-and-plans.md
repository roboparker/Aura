# Organizations, accounts & plans

Status: **in progress** — Phase 0 (plan entitlements) shipped; Phase 1
(Organizations) is landing in sub-phases.

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
  assigned the `guest` built-in `SpaceRole` in any of the org's spaces — enforced
  in the membership processor + a voter, so a Guest can never be elevated into a
  paying seat's capabilities. A dedicated test is the safety net.

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
  Stripe quantity sync.
- **1d** — PWA: org switcher, org settings/members/roles pages, personal-vs-org
  billing UI.

## Deferred / open
- Space-moves between accounts.
- Org deletion flow (export + reassign, like account deletion) — the
  `space.organization` FK is `CASCADE` at the DB layer but no app flow triggers
  it yet.
- AI usage-pack overflow (Phase 3), Enterprise SSO/SCIM/audit enforcement
  (Phase 4).
