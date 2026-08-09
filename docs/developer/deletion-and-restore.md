# Deletion, grace periods & restore links

Three things in Madori can be deleted in a way that reaches other people's work:
an **organization** (cascades to its spaces), a **space** (cascades to its
boards, tasks, pages, comments and attachments), and an **account** (reassigns
everything it authored to a sentinel and removes the user).

All three used to happen on confirm. All three now schedule instead.

## The shape

1. **Schedule.** The record gets `deleted_at` + `purge_after` stamped, a hashed
   single-use `RestoreToken` is minted, and everyone with standing gets an email
   containing `${APP_FRONTEND_URL}/restore/{token}`.
2. **Hide.** Access stops immediately — the grace period only means something if
   people stop working in the thing that's about to vanish.
3. **Restore**, any time before the window lapses, from the email link or in-app.
4. **Purge.** A nightly job does the real delete.

Window: `app.deletion_grace_days` (default 30, env `DELETION_GRACE_DAYS`). One
knob for all three deliberately — a space that outlived its organization's
window would be confusing to explain and worse to reason about.

`purge_after` is **stored, not derived** from `deleted_at + N`. Shortening the
setting must never bring forward a date someone was already shown.

## Code map

| Piece | Where |
|---|---|
| State + transitions | `App\Deletion\SoftDeletable` + `SoftDeletableTrait` |
| Schedule / token / email | `App\Deletion\SoftDeletionService` |
| Nightly sweep | `App\Deletion\PurgeRunner`, `App\Message\PurgeDeletedRecords` |
| Per-type work | `OrganizationDeletionService`, `SpaceDeletionService`, `AccountDeletionService` |
| Restore endpoint | `App\Controller\RestoreController` |
| Email | `App\Service\DeletionNoticeMailer` + `templates/emails/deletion_scheduled.*` |

Manual run: `bin/console app:deletions:purge [--dry-run]`.

Purge **order matters** and is fixed in `PurgeRunner`: organizations, then
spaces, then accounts. Orgs first because purging one takes its spaces with it;
accounts last because an account purge reassigns authorship to the sentinel, and
doing that first would leave the sentinel owning content that's about to
disappear anyway.

## Why the restore endpoint is public

`GET|POST /restore/{token}` is `PUBLIC_ACCESS`. That is a necessity, not a
convenience: an account inside its grace period **cannot sign in** (that's the
point of the window), so requiring a session would make the link useless for the
one case that needs it most.

The exposure is bounded by restore being *non-destructive*. The worst a leaked
link does is bring back something whose owner can simply delete it again. Tokens
are sha256-hashed like password-reset tokens, single-use, and retired whenever
the target is restored in-app or purged — so a stale email can't act on a newer
decision.

## Access while deleted

The guard is folded into `SpaceMembershipDql::userBelongsToBoardSpace()`, which
every space-scoped resource already routes through, so a deleted space *or* a
deleted organization stops serving content everywhere at once. Four places
inline their own membership predicate and were updated alongside it
(`TaskOwnerExtension`, `CommentAccessExtension`, `SpaceAccessExtension`,
`MediaObjectDownloadController`), plus the MCP space listing.

**Item reads stay open** on the org and space themselves, so their admins can
load the "scheduled for deletion, restorable until…" page. Only the *contents*
are hidden.

For accounts, `UserChecker` refuses the sign-in outright, and
`User::getRoles()` returns `[]` as a second line of defence covering any session
or token minted before the deletion landed. That's the same fail-closed idiom as
waitlisting and email verification, taken one step further.

## Sign-in during the window

Deliberately refused, not auto-restored — which is the opposite of what
`deactivatedAt` does, and the difference is the point:

- **Deactivated** is a pause; coming back and authenticating ends it.
- **Deleted** is a decision; undoing it should be deliberate and go through the
  emailed link.

Auto-restoring on sign-in would also mean anyone holding the password can
silently cancel a deletion, and would make the two states indistinguishable in
practice.

## GDPR

A 30-day window is well inside GDPR's "without undue delay", and matches what
GitHub, Google and Slack do. The confirmation dialog and the email both state
the date explicitly. Billing is cancelled at *schedule* time, not at purge —
charging someone through a window they're locked out of would be indefensible.

If an erasure request needs to be honoured faster than the window, run
`app:deletions:purge` once the record is past its date, or clear `purge_after`
manually and re-run. A self-serve "delete permanently now" option was considered
and deferred.

## Exports

Scheduling a deletion queues a space data export so the content is retrievable.
Note these follow **normal export retention**
(`app.space_export_retention_days`, default 7), which is *shorter* than the
grace period: the emailed download link is the delivery mechanism, not an
archive that waits around for the purge.

## PWA

| Surface | Component |
|---|---|
| Email landing page | `pwa/pages/restore/[token].tsx` (works signed out) |
| "Scheduled for deletion" bar + in-app restore | `components/deletion/ScheduledDeletionBanner.tsx` |
| Route back when the email is lost | `components/deletion/RecentlyDeletedList.tsx` |
| Shared types + date helpers | `pwa/lib/deletionTypes.ts` |

Delete dialogs across all three lead with what *doesn't* happen — nothing is
destroyed today, 30 days to undo, link emailed. The previous "this cannot be
undone" copy was about to become false, and the true version is more reassuring
anyway.

Tests: `App\Tests\Api\OrganizationDeletionTest`, `AccountLifecycleTest`,
`App\Tests\Command\PurgeDeletedRecordsCommandTest`.
