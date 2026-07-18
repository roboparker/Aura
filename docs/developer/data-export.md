# Data export — internals

Madori has two asynchronous data exports that share the same machinery: a **space export** (an admin bundles a whole space's content) and an **account export** (a user downloads their own data for GDPR/CCPA). Both build a zip on the worker, mint a single-use download token at completion, email the requester a link, and prune the archive after a retention window.

End users should read [../user/data-export.md](../user/data-export.md) instead.

## At a glance

| | Space export | Account export |
| - | - | - |
| Request | `POST /spaces/{id}/export` | `POST /me/export` |
| Who | Space admin (or global admin) | The user, for their own data |
| Auth on request | Space-admin gate | Step-up via `SensitiveActionVerifier` (TOTP or password) |
| Scope | Every member's content in the space | The requester's own authored/owned content |
| Entity | [`SpaceExport`](../../api/src/Entity/SpaceExport.php) | [`AccountExport`](../../api/src/Entity/AccountExport.php) |
| Build message | `GenerateSpaceExport` | `GenerateAccountExport` |
| Builder | `SpaceExportBuilder` | `AccountExportBuilder` |
| Mailer / templates | `SpaceExportMailer` · `emails/space_export.*` | `AccountExportMailer` · `emails/account_export.*` |
| Download routes | `GET /space-exports/{token}[/download]` | `GET /account-exports/{token}[/download]` |
| Download gate | Requester **or** space admin (or global admin) | **Requester only** — no admin override |
| Landing page | `pwa/pages/exports/[token].tsx` | `pwa/pages/account-exports/[token].tsx` |
| Retention param | `app.space_export_retention_days` (7) | `app.account_export_retention_days` (7) |
| Pruner | `SpaceExportPruner` (03:30 UTC) | `AccountExportPruner` (03:45 UTC) |
| Prune command | `app:space-exports:prune` | `app:account-exports:prune` |

Everything below is written for the account export; the space export is the same shape with a `Space` FK instead of being scoped to one user, and a broader download-access bar.

## Flow

1. **Request** — `POST /me/export` (in [`AccountLifecycleController`](../../api/src/Controller/AccountLifecycleController.php)) runs the step-up check, refuses with **409** if a `pending`/`processing` row already exists for the user (one export at a time), inserts a `pending` `AccountExport`, and dispatches `GenerateAccountExport`. Returns **202** with `{id, status}`. It does *not* build anything — the request stays fast.
2. **Build** — [`GenerateAccountExportHandler`](../../api/src/MessageHandler/GenerateAccountExportHandler.php) flips the row to `processing`, calls `AccountExportBuilder::build()`, then on success stamps `filePath`, `fileSize`, `tokenHash`, `completedAt`, `expiresAt` and flips to `completed`. A build error marks the row `failed` and rethrows so Messenger retries.
3. **Supersede** — once a build completes it deletes every *other* `AccountExport` row (and file) for that user in the same flush, so the newest archive always wins. The 409 only blocks two *concurrent* builds; this collapses an older completed archive when its replacement finishes.
4. **Email** — `AccountExportMailer` sends the "ready" mail with a link to the PWA landing page carrying the plaintext token.
5. **Download** — [`AccountExportController`](../../api/src/Controller/AccountExportController.php) resolves the token (sha256-compared), checks the caller is the requester, and streams the zip via `BinaryFileResponse`.
6. **Prune** — `AccountExportPruner` runs nightly (and on demand) to delete archives + rows past the window.

## Token model

The download token follows the [`PasswordResetToken`](password-reset.md#token-model) model: only the sha256 hash is persisted, the plaintext only ever lives in the email.

```php
$plainToken = bin2hex(random_bytes(32));        // 64 hex chars, 256 bits
$export->setTokenHash(hash('sha256', $plainToken));
$export->setExpiresAt($now->add(new \DateInterval('P7D')));
```

The token is minted **at completion time, inside the handler** — never at request time — so the plaintext never rides through the message queue (a queued `GenerateAccountExport` carries only the row id). `isDownloadable()` is `status === completed && expiresAt > now`.

`token_hash` is `UNIQUE` but nullable; Postgres allows multiple NULLs, so in-flight rows (no token yet) don't collide.

## The archive

`AccountExportBuilder` reuses [`UserDataExporter`](../../api/src/Service/UserDataExporter.php) — the same own-data scoping that backed the old synchronous export — for `account.json`, then adds the uploaded files:

```
account.json      profile, preferences, tasks, boards, pages, discussions,
                  comments, tags, API tokens, + an `attachments` manifest
attachments/      every MediaObject the user uploaded (avatar + attachments),
                  named "<mediaId>-<originalName>"
```

Co-appearing third parties are referenced by id only — no third-party PII leaks through the archive.

Build mechanics (shared with `SpaceExportBuilder`):

- The zip is written to a `.tmp` path and `rename()`d into place only on success, so a crashed build never leaves a truncated file that looks finished.
- Attachment bytes are streamed through the flysystem `media.storage` operator into staged temp files in a `.parts/` directory, then handed to `ZipArchive::addFile()` (read lazily at `close()`) — the worker never holds a whole attachment in memory, and a later local→S3 swap doesn't touch this class.
- A missing attachment file is skipped, not fatal: the manifest still records that it existed.

Archives are written under `app.account_export_dir` (= `var/exports`, filename-prefixed `account-export-*`). This is under `var/` — **not** `var/media` — so the files are never reachable through the public `/media/*` Caddy route. Downloads only go through the authenticated, token-gated endpoint.

## Access control

Both download routes return **404** for unknown, foreign, and expired tokens so the endpoint can't be used to probe which exports exist. The split:

- **Space export** — requester, any admin of the export's space, or a global admin. A space export bundles every member's content, so the download bar matches the request bar (space-admin).
- **Account export** — the **requester only**. Account data is personal; there is deliberately no admin override.

`security.yaml` gates `^/account-exports/` (and `^/me/(deactivate|export|delete)$`) at `IS_AUTHENTICATED_FULLY` rather than `ROLE_USER`, so a **waitlisted account** (which holds only `ROLE_WAITLISTED`) keeps its GDPR self-service — request and download — while waiting.

## Retention & pruning

`AccountExportPruner` deletes:

1. completed exports whose `expiresAt` has passed, and
2. rows that never completed (`pending`/`processing`/`failed`, no expiry) once they age past the retention window — covers a job whose retries were exhausted or whose worker died mid-build.

A final filesystem sweep removes orphaned archives (`account-export-*.zip`, `.zip.tmp`, `.parts/`) whose mtime is older than the window — e.g. a row that was `CASCADE`-deleted with its user, or a `.parts/` directory left by a hard kill.

It runs nightly at **03:45 UTC** via `PruneAccountExports` on the [scheduler](job-queue.md) (`MainScheduleProvider`), and on demand with `bin/console app:account-exports:prune`.

## Test transport note

In `when@test`, the `async` Messenger transport is `sync://`, so `POST /me/export` builds the zip and sends the email **inside the request**. [`AccountExportTest`](../../api/tests/Api/AccountExportTest.php) leans on this: it POSTs, reads the token out of the sent email, then exercises the status + download endpoints. The request still returns 202 — the inline execution is an artifact of the sync transport, not a different code path.

## Adding a third export

Mirror one of the existing pairs end to end: entity (+ migration), `Generate*` message + handler, `*Builder`, `*Mailer` + two twig templates, `*Pruner` + `Prune*` message/handler + `app:*:prune` command, a controller for status + download, the `services.yaml` params, `messenger.yaml` routing for the build message, the `MainScheduleProvider` prune entry, and a `security.yaml` access-control line for the download routes.
